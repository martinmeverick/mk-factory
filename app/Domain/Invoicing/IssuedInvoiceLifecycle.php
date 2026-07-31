<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money\InvoiceTotalsCalculator;
use App\Enums\IssuedInvoiceStatus;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\InvoiceNumberSeries;
use App\Models\IssuedInvoice;
use App\Models\Organization;
use App\Models\OrganizationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Jediné místo, kde se mění stavy vydaných faktur (viz INVOICE_LIFECYCLE.md).
 * Každý přechod běží v transakci a zapisuje AuditLog.
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
    ) {
    }

    public function issue(IssuedInvoice $invoice, ?CarbonImmutable $issueDate = null): void
    {
        $this->assertTransition($invoice->status, IssuedInvoiceStatus::Issued);

        $organization = $this->organizationOf($invoice);

        if (! $invoice->items()->exists()) {
            throw InvoiceNotIssuable::because('faktura nemá žádnou položku.');
        }

        if ($invoice->contact_id === null) {
            throw InvoiceNotIssuable::because('není vyplněn odběratel.');
        }

        if ($invoice->number_series_id === null) {
            throw InvoiceNotIssuable::because('není vybrána číselná řada.');
        }

        // Bankovní účet je povinný, jen pokud organizace nějaký má.
        if ($invoice->bank_account_id === null
            && $organization->bankAccounts()->withoutGlobalScope('organization')->exists()) {
            throw InvoiceNotIssuable::because('není vybrán bankovní účet.');
        }

        DB::transaction(function () use ($invoice, $issueDate, $organization): void {
            $this->totalsCalculator->recalculate($invoice);

            /** @var InvoiceNumberSeries $series */
            $series = $invoice->numberSeries()->withoutGlobalScope('organization')->firstOrFail();
            $number = $this->numberGenerator->nextNumber($series);

            $variableSymbol = $invoice->variable_symbol;

            if ($variableSymbol === null || $variableSymbol === '') {
                // Číslice z čísla faktury, max 10 (zprava — zachová pořadovou část).
                $variableSymbol = substr((string) preg_replace('/\D/', '', $number), -10);
            }

            $settings = $organization->settings()->withoutGlobalScope('organization')->first();
            $resolvedIssueDate = $issueDate ?? CarbonImmutable::today();
            $dueDate = $invoice->due_date
                ?? $resolvedIssueDate->addDays($settings?->default_due_days ?? 14);

            $invoice->allowLifecycleTransition()->forceFill([
                'status' => IssuedInvoiceStatus::Issued,
                'invoice_number' => $number,
                'variable_symbol' => $variableSymbol,
                'issue_date' => $resolvedIssueDate,
                'due_date' => $dueDate,
                'supplier_snapshot' => $this->supplierSnapshot($organization),
                'customer_snapshot' => $this->customerSnapshot($invoice),
                'bank_account_snapshot' => $this->bankAccountSnapshot($invoice),
                'footer_text' => $settings?->invoice_footer_text,
                'issued_at' => now(),
            ])->save();

            $this->auditLogger->log('invoice.issued', $invoice, [
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

        if (! in_array($invoice->status, [IssuedInvoiceStatus::Issued, IssuedInvoiceStatus::PartiallyPaid], true)) {
            throw InvalidStateTransition::because(
                'Platbu lze evidovat jen u vystavené či částečně uhrazené faktury.'
            );
        }

        DB::transaction(function () use ($invoice, $amountMinor, $paidOn, $note): void {
            $previousStatus = $invoice->status;

            $invoice->payments()->create([
                'organization_id' => $invoice->organization_id,
                'amount_minor' => $amountMinor,
                'currency' => $invoice->currency,
                'paid_on' => $paidOn,
                'note' => $note,
            ]);

            $newPaidAmount = $invoice->paid_amount_minor + $amountMinor;
            $newStatus = $newPaidAmount >= $invoice->total_minor
                ? IssuedInvoiceStatus::Paid
                : IssuedInvoiceStatus::PartiallyPaid;

            if ($newStatus !== $previousStatus) {
                $this->assertTransition($previousStatus, $newStatus);
            }

            $invoice->allowLifecycleTransition()->forceFill([
                'paid_amount_minor' => $newPaidAmount,
                'status' => $newStatus,
                'paid_at' => $newStatus === IssuedInvoiceStatus::Paid ? $paidOn : $invoice->paid_at,
            ])->save();

            $this->auditLogger->log('invoice.payment_registered', $invoice, [
                'amount_minor' => $amountMinor,
                'paid_on' => $paidOn->toDateString(),
                'status' => ['from' => $previousStatus->value, 'to' => $newStatus->value],
            ]);
        });
    }

    /**
     * Zkratka: zaregistruje platbu zbývající částky.
     */
    public function markPaid(IssuedInvoice $invoice, CarbonImmutable $paidOn): void
    {
        $this->registerPayment(
            $invoice,
            $invoice->remainingMoney()->getMinor(),
            $paidOn,
        );
    }

    public function cancel(IssuedInvoice $invoice): void
    {
        $this->assertTransition($invoice->status, IssuedInvoiceStatus::Cancelled);

        if ($invoice->paid_amount_minor > 0
            || $invoice->payments()->withoutGlobalScope('organization')->exists()) {
            throw InvalidStateTransition::because(
                'Stornovat lze jen fakturu bez evidovaných plateb.'
            );
        }

        DB::transaction(function () use ($invoice): void {
            $previousStatus = $invoice->status;

            // Číslo faktury zůstává spotřebované — řada se nevrací (auditní stopa).
            $invoice->allowLifecycleTransition()->forceFill([
                'status' => IssuedInvoiceStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            $this->auditLogger->log('invoice.cancelled', $invoice, [
                'status' => ['from' => $previousStatus->value, 'to' => IssuedInvoiceStatus::Cancelled->value],
            ]);
        });
    }

    private function assertTransition(IssuedInvoiceStatus $from, IssuedInvoiceStatus $to): void
    {
        if (! in_array($to->value, self::TRANSITIONS[$from->value], true)) {
            throw InvalidStateTransition::between($from->value, $to->value);
        }
    }

    private function organizationOf(IssuedInvoice $invoice): Organization
    {
        return $invoice->organization()->firstOrFail();
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
