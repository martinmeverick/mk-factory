<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money\InvoiceTotalsCalculator;
use App\Domain\Money\Money;
use App\Domain\Pdf\InvoiceLogoSnapshotStore;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\IssuedInvoiceStatus;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\IssuedInvoice;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Jediné místo, kde se mění stavy vydaných faktur (viz INVOICE_LIFECYCLE.md).
 *
 * Každá mutace jede podle stejného vzoru:
 *   transakce → tenant-scoped dotaz → lockForUpdate() → načtení aktuální
 *   faktury → kontrola stavu → doménová validace → změna → audit → commit.
 *
 * Kontrola stavu se NIKDY nedělá nad instancí předanou volajícím — ta může
 * být zastaralá. Rozhoduje výhradně zamčený řádek v databázi.
 */
final class IssuedInvoiceLifecycle
{
    /**
     * Mapa povolených přechodů — jediné místo pravdy.
     */
    private const array TRANSITIONS = [
        'draft' => ['issued'],
        'issued' => ['partially_paid', 'paid', 'cancelled'],
        'partially_paid' => ['paid'],
        'paid' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private readonly InvoiceNumberGenerator $numberGenerator,
        private readonly InvoiceTotalsCalculator $totalsCalculator,
        private readonly AuditLogger $auditLogger,
        private readonly InvoiceLogoSnapshotStore $logoSnapshots,
        private readonly CurrentOrganization $currentOrganization,
    ) {
    }

    public function issue(IssuedInvoice $invoice, ?CarbonImmutable $issueDate = null): void
    {
        $this->mutate($invoice, function (IssuedInvoice $locked) use ($issueDate): void {
            $this->assertTransition($locked->status, IssuedInvoiceStatus::Issued);

            $organization = $locked->organization()->withoutGlobalScope('organization')->firstOrFail();

            if ($locked->contact_id === null) {
                throw InvoiceNotIssuable::because('není vyplněn odběratel.');
            }

            if ($locked->number_series_id === null) {
                throw InvoiceNotIssuable::because('není vybrána číselná řada.');
            }

            // Bankovní účet je povinný, jen pokud organizace nějaký má.
            if ($locked->bank_account_id === null
                && $organization->bankAccounts()->withoutGlobalScope('organization')->exists()) {
                throw InvoiceNotIssuable::because('není vybrán bankovní účet.');
            }

            if (! $locked->items()->withoutGlobalScope('organization')->exists()) {
                throw InvoiceNotIssuable::because('faktura nemá žádnou položku.');
            }

            // Přepočet PŘED validací součtu i před spotřebováním čísla řady:
            // neplatný doklad nesmí číslo spotřebovat.
            $this->totalsCalculator->recalculate($locked);

            $this->assertPositiveTotal($locked);

            $series = $locked->numberSeries()->withoutGlobalScope('organization')->firstOrFail();
            $number = $this->numberGenerator->nextNumber($series);

            $variableSymbol = $locked->variable_symbol;

            if ($variableSymbol === null || $variableSymbol === '') {
                // Číslice z čísla faktury, max 10 (zprava — zachová pořadovou část).
                $variableSymbol = substr((string) preg_replace('/\D/', '', $number), -10);
            }

            $settings = $organization->settings()->withoutGlobalScope('organization')->first();
            $resolvedIssueDate = $issueDate ?? CarbonImmutable::today();
            $dueDate = $locked->due_date
                ?? $resolvedIssueDate->addDays($settings?->default_due_days ?? 14);

            $locked->applyIssued([
                'status' => IssuedInvoiceStatus::Issued,
                'invoice_number' => $number,
                'variable_symbol' => $variableSymbol,
                'issue_date' => $resolvedIssueDate,
                'due_date' => $dueDate,
                'supplier_snapshot' => $this->supplierSnapshot($organization),
                'customer_snapshot' => $this->customerSnapshot($locked),
                'bank_account_snapshot' => $this->bankAccountSnapshot($locked),
                'footer_text' => $settings?->invoice_footer_text,
                // Logo se zmrazí kopií — pozdější změna či smazání firemního
                // loga nesmí změnit historický doklad.
                'logo_snapshot_path' => $this->logoSnapshots->capture($organization, $locked),
                'issued_at' => now(),
            ]);

            $this->auditLogger->log('invoice.issued', $locked, [
                'status' => ['from' => IssuedInvoiceStatus::Draft->value, 'to' => IssuedInvoiceStatus::Issued->value],
                'invoice_number' => $number,
            ]);
        });
    }

    public function registerPayment(
        IssuedInvoice $invoice,
        int $amountMinor,
        CarbonImmutable $paidOn,
        ?string $note = null,
    ): void {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Částka platby musí být kladná.');
        }

        $this->mutate($invoice, function (IssuedInvoice $locked) use ($amountMinor, $paidOn, $note): void {
            $this->applyPayment($locked, $amountMinor, $paidOn, $note);
        });
    }

    /**
     * Doplatí zbývající částku. Zbytek se počítá až nad zamčeným řádkem,
     * takže souběžné volání nevytvoří druhou doplatkovou platbu.
     */
    public function markPaid(IssuedInvoice $invoice, CarbonImmutable $paidOn): void
    {
        $this->mutate($invoice, function (IssuedInvoice $locked) use ($paidOn): void {
            $remaining = $locked->total_minor - $locked->paid_amount_minor;

            if ($remaining <= 0) {
                throw InvalidStateTransition::because(
                    'Faktura už nemá co doplatit.'
                );
            }

            $this->applyPayment($locked, $remaining, $paidOn, null);
        });
    }

    public function cancel(IssuedInvoice $invoice): void
    {
        $this->mutate($invoice, function (IssuedInvoice $locked): void {
            $this->assertTransition($locked->status, IssuedInvoiceStatus::Cancelled);

            if ($locked->paid_amount_minor > 0
                || $locked->payments()->withoutGlobalScope('organization')->exists()) {
                throw InvalidStateTransition::because(
                    'Stornovat lze jen fakturu bez evidovaných plateb.'
                );
            }

            $previousStatus = $locked->status;

            // Číslo faktury zůstává spotřebované — řada se nevrací (auditní stopa).
            $locked->applyCancelled(now());

            $this->auditLogger->log('invoice.cancelled', $locked, [
                'status' => ['from' => $previousStatus->value, 'to' => IssuedInvoiceStatus::Cancelled->value],
            ]);
        });
    }

    /**
     * Smazání konceptu. Stav se ověřuje nad zamčeným řádkem, takže souběžné
     * vystavení a smazání se serializují a vystavenou fakturu nelze odstranit.
     */
    public function deleteDraft(IssuedInvoice $invoice): void
    {
        $this->mutate($invoice, function (IssuedInvoice $locked): void {
            if ($locked->status !== IssuedInvoiceStatus::Draft) {
                throw InvalidStateTransition::because('Smazat lze pouze koncept faktury.');
            }

            $locked->items()->withoutGlobalScope('organization')->delete();
            $locked->delete();
        });
    }

    /**
     * Přepis položek konceptu. Editovatelnost se rozhoduje až nad zamčeným
     * řádkem — controller o ní nerozhoduje podle dřív načtené instance.
     *
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $items
     */
    public function updateDraft(IssuedInvoice $invoice, array $header, array $items): void
    {
        $this->mutate($invoice, function (IssuedInvoice $locked) use ($header, $items): void {
            if ($locked->status !== IssuedInvoiceStatus::Draft) {
                throw InvalidStateTransition::because('Upravovat lze pouze koncept faktury.');
            }

            $locked->forceFill($header)->save();

            $locked->items()->withoutGlobalScope('organization')->delete();

            foreach (array_values($items) as $position => $item) {
                $locked->items()->create($item + [
                    'organization_id' => $locked->organization_id,
                    'position' => $position + 1,
                ]);
            }

            $this->totalsCalculator->recalculate($locked);
        });
    }

    /**
     * Společné jádro obou platebních cest — běží už nad zamčeným řádkem.
     */
    private function applyPayment(
        IssuedInvoice $locked,
        int $amountMinor,
        CarbonImmutable $paidOn,
        ?string $note,
    ): void {
        if (! in_array($locked->status, [IssuedInvoiceStatus::Issued, IssuedInvoiceStatus::PartiallyPaid], true)) {
            throw InvalidStateTransition::because(
                'Platbu lze evidovat jen u vystavené či částečně uhrazené faktury.'
            );
        }

        $previousStatus = $locked->status;

        // Součet přes Money — přetečení skončí doménovou chybou, ne saturací.
        $newPaidAmount = Money::fromMinor($locked->paid_amount_minor, $locked->currency)
            ->plus(Money::fromMinor($amountMinor, $locked->currency));

        $total = Money::fromMinor($locked->total_minor, $locked->currency);

        if ($newPaidAmount->getMinor() > $total->getMinor()) {
            throw InvalidStateTransition::because(sprintf(
                'Platba %s převyšuje zbývající částku %s.',
                Money::fromMinor($amountMinor, $locked->currency)->formatCzech(),
                $total->minus(Money::fromMinor($locked->paid_amount_minor, $locked->currency))->formatCzech(),
            ));
        }

        $locked->payments()->create([
            'organization_id' => $locked->organization_id,
            'amount_minor' => $amountMinor,
            'currency' => $locked->currency,
            'paid_on' => $paidOn,
            'note' => $note,
        ]);

        $newStatus = $newPaidAmount->getMinor() >= $total->getMinor()
            ? IssuedInvoiceStatus::Paid
            : IssuedInvoiceStatus::PartiallyPaid;

        if ($newStatus !== $previousStatus) {
            $this->assertTransition($previousStatus, $newStatus);
        }

        $locked->applyPaymentState(
            $newPaidAmount->getMinor(),
            $newStatus,
            $newStatus === IssuedInvoiceStatus::Paid ? $paidOn : $locked->paid_at,
        );

        $this->auditLogger->log('invoice.payment_registered', $locked, [
            'amount_minor' => $amountMinor,
            'paid_on' => $paidOn->toDateString(),
            'status' => ['from' => $previousStatus->value, 'to' => $newStatus->value],
        ]);
    }

    /**
     * Transakce + tenant-scoped zamčení + provedení operace nad zamčeným
     * záznamem. Volajícího instance se na závěr srovná s DB.
     */
    private function mutate(IssuedInvoice $invoice, callable $operation): void
    {
        $organizationId = $this->expectedOrganizationId($invoice);
        $key = $invoice->getKey();

        if ($key === null) {
            throw new InvalidArgumentException('Fakturu je nutné nejdřív uložit.');
        }

        DB::transaction(function () use ($invoice, $operation, $organizationId, $key): void {
            /** @var IssuedInvoice|null $locked */
            $locked = IssuedInvoice::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->whereKey($key)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw InvoiceNotFound::forKey($key);
            }

            $operation($locked);

            // Volající drží instanci, se kterou dál pracuje (redirecty, PDF).
            if ($locked->exists) {
                $invoice->setRawAttributes($locked->getAttributes(), true);
            } else {
                $invoice->exists = false;
            }
        });
    }

    /**
     * Organizace, na kterou se zamčené načtení omezí. Nastavený tenant
     * context musí souhlasit s organizací faktury — jinak fail closed.
     */
    private function expectedOrganizationId(IssuedInvoice $invoice): int
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

    /**
     * Běžná vydaná faktura musí mít kladný součet. Záporné položky (slevy)
     * zůstávají povolené, dokud je výsledek kladný.
     */
    private function assertPositiveTotal(IssuedInvoice $invoice): void
    {
        if ((int) $invoice->total_minor <= 0) {
            throw InvoiceNotIssuable::because(sprintf(
                'celková částka musí být kladná (aktuálně %s). Dobropisy a nulové doklady zatím nejsou podporované.',
                Money::fromMinor((int) $invoice->total_minor, $invoice->currency)->formatCzech(),
            ));
        }
    }

    private function assertTransition(IssuedInvoiceStatus $from, IssuedInvoiceStatus $to): void
    {
        if (! in_array($to->value, self::TRANSITIONS[$from->value], true)) {
            throw InvalidStateTransition::between($from->value, $to->value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierSnapshot(Organization $organization): array
    {
        return [
            'name' => $organization->name,
            'ico' => $organization->ico,
            'dic' => $organization->dic,
            'street' => $organization->street,
            'city' => $organization->city,
            'zip' => $organization->zip,
            'country' => $organization->country,
            'email' => $organization->email,
            'phone' => $organization->phone,
            'website' => $organization->website,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerSnapshot(IssuedInvoice $invoice): array
    {
        /** @var Contact $contact */
        $contact = $invoice->contact()->withoutGlobalScope('organization')->firstOrFail();

        return [
            'name' => $contact->name,
            'ico' => $contact->ico,
            'dic' => $contact->dic,
            'street' => $contact->street,
            'city' => $contact->city,
            'zip' => $contact->zip,
            'country' => $contact->country,
            'email' => $contact->email,
            'phone' => $contact->phone,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bankAccountSnapshot(IssuedInvoice $invoice): ?array
    {
        /** @var BankAccount|null $account */
        $account = $invoice->bankAccount()->withoutGlobalScope('organization')->first();

        if ($account === null) {
            return null;
        }

        return [
            'account_number' => $account->account_number,
            'bank_code' => $account->bank_code,
            'iban' => $account->iban,
            'bic' => $account->bic,
        ];
    }
}
