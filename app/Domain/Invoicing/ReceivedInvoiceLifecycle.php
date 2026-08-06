<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ReceivedInvoiceStatus;
use App\Models\ReceivedInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
            if (! in_array($locked->status, [ReceivedInvoiceStatus::Received, ReceivedInvoiceStatus::Approved], true)) {
                throw InvalidStateTransition::because('Uhrazenou nebo zamítnutou fakturu nelze upravovat.');
            }

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
     * řádkem: uhrazenou fakturu nesmaže ani zastaralá instance, která ji
     * načetla před markPaid().
     */
    public function delete(ReceivedInvoice $invoice): void
    {
        $attachmentPaths = [];

        $this->mutate($invoice, function (ReceivedInvoice $locked) use (&$attachmentPaths): void {
            if ($locked->status === ReceivedInvoiceStatus::Paid) {
                throw InvalidStateTransition::because('Uhrazenou fakturu nelze smazat.');
            }

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
