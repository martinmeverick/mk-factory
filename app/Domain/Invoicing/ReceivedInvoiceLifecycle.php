<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ReceivedInvoiceStatus;
use App\Models\ReceivedInvoice;
use App\Models\ReceivedInvoiceAttachment;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Jediné místo, kde se mění přijaté faktury (viz INVOICE_LIFECYCLE.md).
 *
 * Stejný vzor jako u vydaných faktur: transakce → tenant-scoped dotaz →
 * lockForUpdate() → kontrola stavu nad ZAMČENÝM řádkem → whitelist →
 * změna → audit. Souběžné protichůdné operace se tím serializují a druhá
 * je odmítnuta podle skutečného aktuálního stavu — zastaralá instance po
 * cizím markPaid() nemůže doklad změnit ani smazat.
 */
final class ReceivedInvoiceLifecycle
{
    /**
     * Mapa povolených přechodů — jediné místo pravdy.
     */
    private const array TRANSITIONS = [
        'received' => ['approved', 'rejected', 'paid'],
        'approved' => ['paid', 'rejected'],
        'paid' => [],
        'rejected' => [],
    ];

    private const string ATTACHMENT_DISK = 'local';

    /**
     * Editovatelná pole přijaté faktury — odvozeno ze skutečného formuláře
     * (ReceivedInvoiceRequest + ReceivedInvoiceController::data()). Tenant
     * identita, stav ani paid_at sem NEPATŘÍ; klíč mimo whitelist je
     * programátorská chyba volajícího a končí výjimkou.
     */
    private const array EDITABLE_ATTRIBUTES = [
        'contact_id',
        'project_id',
        'supplier_invoice_number',
        'variable_symbol',
        'issue_date',
        'received_date',
        'due_date',
        'total_minor',
        'vat_minor',
        'currency',
        'note',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CurrentOrganization $currentOrganization,
    ) {
    }

    public function approve(ReceivedInvoice $invoice): void
    {
        $this->transition($invoice, ReceivedInvoiceStatus::Approved);
    }

    public function reject(ReceivedInvoice $invoice): void
    {
        $this->transition($invoice, ReceivedInvoiceStatus::Rejected);
    }

    public function markPaid(ReceivedInvoice $invoice, CarbonImmutable $paidOn): void
    {
        $this->mutate($invoice, function (ReceivedInvoice $locked) use ($paidOn): void {
            $this->assertTransition($locked->status, ReceivedInvoiceStatus::Paid);

            $previousStatus = $locked->status;

            // Zamčený řádek + kontrola stavu ⇒ druhý souběžný markPaid
            // narazí na stav paid a neuloží druhou platbu.
            $locked->payments()->create([
                'organization_id' => $locked->organization_id,
                'amount_minor' => $locked->total_minor,
                'currency' => $locked->currency,
                'paid_on' => $paidOn,
            ]);

            $this->writeLifecycleState($locked, [
                'status' => ReceivedInvoiceStatus::Paid,
                'paid_at' => $paidOn,
            ]);

            $this->auditLogger->log('invoice.payment_registered', $locked, [
                'amount_minor' => $locked->total_minor,
                'paid_on' => $paidOn->toDateString(),
                'status' => ['from' => $previousStatus->value, 'to' => ReceivedInvoiceStatus::Paid->value],
            ]);
        });
    }

    /**
     * Úprava evidenčních údajů. Editovatelnost rozhoduje AKTUÁLNÍ stav
     * zamčeného řádku — uhrazenou či zamítnutou fakturu zastaralá instance
     * nezmění. Whitelist odmítá tenant, stavová i neznámá pole.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateDetails(ReceivedInvoice $invoice, array $attributes): void
    {
        // Kontrakt se vynucuje PŘED transakcí: nepovolený klíč shodí celé
        // volání — nezapíše se nic, ani legitimní část změny.
        $this->assertOnlyKeys($attributes, self::EDITABLE_ATTRIBUTES, 'přijaté faktury');

        $this->mutate($invoice, function (ReceivedInvoice $locked) use ($attributes): void {
            $this->assertNotFinal($locked, 'Uhrazenou ani zamítnutou fakturu nelze upravovat.');

            $locked->forceFill($attributes);
            $changes = $locked->getDirty();

            if ($changes === []) {
                return;
            }

            $original = array_intersect_key($locked->getOriginal(), $changes);
            $locked->save();

            $changedSummary = [];

            foreach ($changes as $attribute => $newValue) {
                $changedSummary[$attribute] = [
                    'from' => $original[$attribute] ?? null,
                    'to' => $newValue,
                ];
            }

            $this->auditLogger->log('invoice.updated', $locked, ['changed' => $changedSummary]);
        });
    }

    /**
     * Smazání přijaté faktury včetně příloh. Stav se ověřuje nad zamčeným
     * řádkem: fakturu ve finálním stavu (uhrazenou ani zamítnutou) nesmaže
     * ani zastaralá instance, která ji načetla před přechodem.
     */
    public function delete(ReceivedInvoice $invoice): void
    {
        $attachmentPaths = [];

        $this->mutate($invoice, function (ReceivedInvoice $locked) use (&$attachmentPaths): void {
            $this->assertNotFinal($locked, 'Uhrazenou ani zamítnutou fakturu nelze smazat.');

            $attachments = $locked->attachments()->withoutGlobalScope('organization')->get();
            $attachmentPaths = $attachments->pluck('stored_path')->all();

            foreach ($attachments as $attachment) {
                $attachment->delete();
            }

            $previousStatus = $locked->status;

            $locked->delete();

            $this->auditLogger->log('invoice.deleted', $locked, [
                'status' => $previousStatus->value,
                'supplier_invoice_number' => $locked->supplier_invoice_number,
            ]);
        });

        // Soubory se mažou až PO commitu — rollback transakce nesmí nechat
        // živé záznamy bez souborů. Osiřelý soubor po pádu mezi commitem
        // a úklidem je menší zlo (bez odkazu z DB) než chybějící příloha.
        foreach ($attachmentPaths as $path) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * Přidání přílohy. Pořadí je zásadní: transakce → tenant-scoped
     * zamčení → kontrola AKTUÁLNÍHO stavu → teprve pak zápis souboru →
     * DB záznam → audit → commit. Dřív se soubor ukládal jako první,
     * takže odmítnutá příloha po sobě nechala osiřelý soubor na disku.
     *
     * Filesystem není součástí DB transakce, proto navíc kompenzace:
     * když cokoli po zápisu souboru selže, soubor se smaže.
     *
     * @throws InvalidStateTransition|InvoiceNotFound|AttachmentStorageFailed
     */
    public function attach(ReceivedInvoice $invoice, UploadedFile $file): ReceivedInvoiceAttachment
    {
        $storedPath = null;
        $attachment = null;

        try {
            $this->mutate($invoice, function (ReceivedInvoice $locked) use ($file, &$storedPath, &$attachment): void {
                $this->assertNotFinal($locked, 'K uhrazené ani zamítnuté faktuře nelze přidat přílohu.');

                $path = $file->store('attachments/org-'.$locked->organization_id, self::ATTACHMENT_DISK);

                if (! is_string($path) || $path === '') {
                    throw AttachmentStorageFailed::forUpload($file->getClientOriginalName());
                }

                $storedPath = $path;

                if (! Storage::disk(self::ATTACHMENT_DISK)->exists($path)) {
                    throw AttachmentStorageFailed::forUpload($file->getClientOriginalName());
                }

                $attachment = $locked->attachments()->create([
                    'organization_id' => $locked->organization_id,
                    'original_filename' => $file->getClientOriginalName(),
                    'stored_path' => $path,
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size_bytes' => $file->getSize() ?: 0,
                ]);

                $this->auditLogger->log('invoice.attachment_added', $locked, [
                    'original_filename' => $attachment->original_filename,
                    'size_bytes' => $attachment->size_bytes,
                ]);
            });
        } catch (\Throwable $e) {
            // DB je odvalená; soubor by bez tohoto úklidu zůstal osiřelý.
            if ($storedPath !== null) {
                Storage::disk(self::ATTACHMENT_DISK)->delete($storedPath);
            }

            throw $e;
        }

        return $attachment;
    }

    /**
     * Smazání JEDNÉ přílohy. Musí jet stejným vzorem jako ostatní mutace
     * přijaté faktury, protože finalita dokladu je vlastnost RODIČE:
     * transakce → tenant-scoped zamčení RODIČE (lockForUpdate) → kontrola
     * aktuálního stavu nad zamčeným řádkem → znovunačtení přílohy pod
     * zamčeným rodičem → DB delete → audit → commit → teprve pak soubor.
     *
     * Dřív mazal přílohu přímo controller (`$attachment->delete()`). Guard
     * na modelu sice četl stav z DATABÁZE, ale bez zámku: mezi jeho čtením
     * a samotným DELETE se vešel cizí `markPaid()`, takže příloha zmizela
     * až PO finalizaci dokladu (reprodukováno nad MariaDB:
     * `phase=checked result=deleted final_status=paid attachment_count=0`).
     * Zámek rodiče tenhle okamžik uzavírá — druhá operace čeká a rozhoduje
     * se podle skutečného stavu.
     *
     * Pořadí zámků je shodné s `delete()` a `attach()` (nejdřív rodič, pak
     * potomek), aby nevznikl nový deadlock pattern.
     *
     * @return bool zda se po commitu podařilo odstranit i soubor na disku;
     *              `false` znamená osiřelý soubor bez odkazu z DB (viz níže)
     *
     * @throws InvalidStateTransition|InvoiceNotFound|AttachmentNotFound
     */
    public function deleteAttachment(ReceivedInvoiceAttachment $attachment): bool
    {
        $attachmentKey = $attachment->getKey();

        if (! $attachment->exists || $attachmentKey === null) {
            throw new InvalidArgumentException('Přílohu je nutné nejdřív uložit.');
        }

        // Rodič i tenant se berou z ULOŽENÝCH hodnot, ne z instance —
        // podvržené received_invoice_id/organization_id v paměti by jinak
        // guard přesměrovalo na jiný, nefinální doklad.
        $invoiceId = $attachment->getOriginal('received_invoice_id');
        $organizationId = $attachment->getOriginal('organization_id');

        if ($invoiceId === null || $organizationId === null) {
            throw AttachmentNotFound::forKey($attachmentKey);
        }

        /** @var ReceivedInvoice|null $invoice */
        $invoice = ReceivedInvoice::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereKey($invoiceId)
            ->first();

        if ($invoice === null) {
            throw InvoiceNotFound::forKey($invoiceId);
        }

        $storedPath = null;

        // mutate() ověří tenant context proti organizaci faktury a zamkne
        // řádek rodiče — cizí organizace tedy skončí na InvoiceNotFound.
        $this->mutate($invoice, function (ReceivedInvoice $locked) use ($attachmentKey, &$storedPath): void {
            $this->assertNotFinal($locked, 'Přílohu uhrazené ani zamítnuté faktury nelze smazat.');

            /** @var ReceivedInvoiceAttachment|null $fresh */
            $fresh = ReceivedInvoiceAttachment::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $locked->organization_id)
                ->where('received_invoice_id', $locked->getKey())
                ->whereKey($attachmentKey)
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                throw AttachmentNotFound::forKey($attachmentKey);
            }

            $storedPath = (string) $fresh->stored_path;

            $fresh->delete();

            $this->auditLogger->log('invoice.attachment_removed', $locked, [
                'original_filename' => $fresh->original_filename,
                'size_bytes' => $fresh->size_bytes,
            ]);
        });

        return $this->removeStoredFile($storedPath);
    }

    /**
     * Odstranění souboru přílohy AŽ PO commitu.
     *
     * Bezpečný směr je jen jeden: nejdřív zmizí DB reference, pak soubor.
     * Pád mezi commitem a úklidem nechá osiřelý soubor bez odkazu z DB —
     * to je přijatelné a uklidí to provozní kompenzace (viz
     * docs/INVOICE_LIFECYCLE.md). Opačné pořadí by při rollbacku nechalo
     * živý záznam ukazovat na neexistující soubor, což je nepřijatelné.
     *
     * Selhání se proto NEVRACÍ do DB — jen se řízeně ohlásí volajícímu
     * a zaloguje.
     */
    private function removeStoredFile(?string $path): bool
    {
        if ($path === null || $path === '') {
            return true;
        }

        try {
            $deleted = Storage::disk(self::ATTACHMENT_DISK)->delete($path);
        } catch (\Throwable $e) {
            Log::warning('Soubor smazané přílohy se nepodařilo odstranit.', [
                'disk' => self::ATTACHMENT_DISK,
                'path' => $path,
                'exception' => $e::class.': '.$e->getMessage(),
            ]);

            return false;
        }

        if (! $deleted) {
            Log::warning('Soubor smazané přílohy zůstal na disku jako osiřelý.', [
                'disk' => self::ATTACHMENT_DISK,
                'path' => $path,
            ]);
        }

        return $deleted;
    }

    /**
     * Finalitu určuje JEDINÝ zdroj pravdy ReceivedInvoice::FINAL_STATUSES
     * a posuzuje se nad ZAMČENÝM řádkem, ne nad instancí volajícího.
     */
    private function assertNotFinal(ReceivedInvoice $locked, string $message): void
    {
        if ($locked->isFinal()) {
            throw InvalidStateTransition::because($message);
        }
    }

    private function transition(ReceivedInvoice $invoice, ReceivedInvoiceStatus $to): void
    {
        $this->mutate($invoice, function (ReceivedInvoice $locked) use ($to): void {
            $this->assertTransition($locked->status, $to);

            $previousStatus = $locked->status;

            $this->writeLifecycleState($locked, ['status' => $to]);

            $this->auditLogger->log('invoice.status_changed', $locked, [
                'status' => ['from' => $previousStatus->value, 'to' => $to->value],
            ]);
        });
    }

    /**
     * Interní zápis stavových polí nad zamčeným modelem — vazba do
     * privátního scope ReceivedInvoice (obdoba friend třídy). Veřejná
     * zápisová metoda stavových polí na modelu neexistuje.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function writeLifecycleState(ReceivedInvoice $locked, array $attributes): void
    {
        \Closure::bind(
            function (array $attributes): void {
                /** @var ReceivedInvoice $this */
                $this->persistLifecycleState($attributes);
            },
            $locked,
            ReceivedInvoice::class,
        )($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $allowed
     */
    private function assertOnlyKeys(array $attributes, array $allowed, string $subject): void
    {
        $unexpected = array_diff(array_keys($attributes), $allowed);

        if ($unexpected !== []) {
            throw new InvalidArgumentException(sprintf(
                'Nepovolené atributy %s: %s.',
                $subject,
                implode(', ', $unexpected),
            ));
        }
    }

    /**
     * Transakce + tenant-scoped zamčení + operace nad zamčeným záznamem.
     */
    private function mutate(ReceivedInvoice $invoice, callable $operation): void
    {
        $organizationId = $this->expectedOrganizationId($invoice);
        $key = $invoice->getKey();

        if ($key === null) {
            throw new InvalidArgumentException('Fakturu je nutné nejdřív uložit.');
        }

        DB::transaction(function () use ($invoice, $operation, $organizationId, $key): void {
            /** @var ReceivedInvoice|null $locked */
            $locked = ReceivedInvoice::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->whereKey($key)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw InvoiceNotFound::forKey($key);
            }

            $operation($locked);

            // Volající drží instanci, se kterou dál pracuje (redirecty).
            if ($locked->exists) {
                $invoice->setRawAttributes($locked->getAttributes(), true);
            } else {
                $invoice->exists = false;
            }
        });
    }

    /**
     * Nastavený tenant context musí souhlasit s organizací faktury.
     */
    private function expectedOrganizationId(ReceivedInvoice $invoice): int
    {
        $invoiceOrganizationId = $invoice->organization_id;

        if ($invoiceOrganizationId === null) {
            throw new InvalidArgumentException('Faktura nemá přiřazenou organizaci.');
        }

        $current = $this->currentOrganization->id();

        if ($current !== null && $current !== (int) $invoiceOrganizationId) {
            throw InvoiceNotFound::forKey($invoice->getKey());
        }

        return (int) $invoiceOrganizationId;
    }

    private function assertTransition(ReceivedInvoiceStatus $from, ReceivedInvoiceStatus $to): void
    {
        if (! in_array($to->value, self::TRANSITIONS[$from->value], true)) {
            throw InvalidStateTransition::between($from->value, $to->value);
        }
    }
}
