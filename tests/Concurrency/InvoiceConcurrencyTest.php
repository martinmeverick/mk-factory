<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Domain\Invoicing\IssuedInvoiceLifecycle;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\IssuedInvoiceStatus;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\InvoiceNumberSeries;
use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use App\Models\Organization;
use App\Models\OrganizationSettings;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * NÁLEZ 3: vystavení a platby musí být serializované skutečnými zámky.
 *
 * Každý test forkuje samostatné procesy s vlastním DB spojením — dvě
 * Eloquent instance nad jedním spojením by souběh neprokázaly.
 *
 * Workery se synchronizují bariérou (viz ConcurrencyTestCase): přípravu
 * (vlastní spojení, načtení faktury) dokončí PŘED ní a do kritické
 * operace vstupují současně, až když rodič potvrdí připravenost obou.
 */
class InvoiceConcurrencyTest extends ConcurrencyTestCase
{
    private Organization $organization;

    private function seedOrganization(): void
    {
        $this->organization = Organization::withoutGlobalScope('organization')->create([
            'name' => 'Souběh s.r.o.',
            'country' => 'CZ',
        ]);

        OrganizationSettings::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'vat_payer' => false,
            'default_due_days' => 14,
        ]);

        BankAccount::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'name' => 'Provozní',
            'account_number' => '123456789',
            'bank_code' => '0100',
            'iban' => 'CZ1801000000000123456789',
            'is_default' => true,
        ]);
    }

    private function makeDraft(int $totalMinor = 10000): IssuedInvoice
    {
        $contact = Contact::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'type' => 'customer',
            'name' => 'Odběratel '.uniqid(),
            'country' => 'CZ',
        ]);

        $series = InvoiceNumberSeries::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'name' => 'Faktury',
            'prefix' => 'FV',
            'year' => 2026,
            'next_number' => 1,
            'number_format' => '{PREFIX}{YEAR}{NUMBER:4}',
        ]);

        $account = BankAccount::withoutGlobalScope('organization')
            ->where('organization_id', $this->organization->id)->firstOrFail();

        $invoice = IssuedInvoice::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'contact_id' => $contact->id,
            'number_series_id' => $series->id,
            'bank_account_id' => $account->id,
            'status' => IssuedInvoiceStatus::Draft,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-15',
            'currency' => 'CZK',
        ]);

        IssuedInvoiceItem::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'issued_invoice_id' => $invoice->id,
            'position' => 1,
            'description' => 'Práce',
            'quantity' => '1',
            'unit' => 'ks',
            'unit_price_minor' => $totalMinor,
            'vat_rate' => null,
            'line_subtotal_minor' => $totalMinor,
            'line_vat_minor' => 0,
            'line_total_minor' => $totalMinor,
        ]);

        return $invoice;
    }

    /**
     * Uvnitř potomka: čerstvý tenant context a čerstvá instance faktury.
     */
    private function lifecycleFor(int $invoiceId): array
    {
        app(CurrentOrganization::class)->set(
            Organization::withoutGlobalScope('organization')->findOrFail($this->organization->id)
        );

        $invoice = IssuedInvoice::withoutGlobalScope('organization')->findOrFail($invoiceId);

        return [app(IssuedInvoiceLifecycle::class), $invoice];
    }

    public function test_two_concurrent_payments_do_not_lose_an_amount(): void
    {
        $this->seedOrganization();
        $invoice = $this->makeDraft(10000);

        app(CurrentOrganization::class)->set($this->organization);
        app(IssuedInvoiceLifecycle::class)->issue($invoice, CarbonImmutable::parse('2026-08-01'));
        app(CurrentOrganization::class)->set(null);

        $invoiceId = $invoice->id;

        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->registerPayment($fresh, 4000, CarbonImmutable::parse('2026-08-02'), 'A');
            },
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->registerPayment($fresh, 3000, CarbonImmutable::parse('2026-08-02'), 'B');
            },
        ]);

        $this->assertSame(['', ''], $errors, 'Obě platby musí projít: '.implode(' | ', $errors));

        $fresh = IssuedInvoice::withoutGlobalScope('organization')->findOrFail($invoiceId);

        // Bez zámku by druhý zápis přepsal první a zůstalo by 4000 nebo 3000.
        $this->assertSame(7000, (int) $fresh->paid_amount_minor);
        $this->assertSame(
            2,
            Payment::withoutGlobalScope('organization')
                ->where('payable_id', $invoiceId)
                ->where('payable_type', IssuedInvoice::class)
                ->count(),
        );
        $this->assertSame(IssuedInvoiceStatus::PartiallyPaid, $fresh->status);
    }

    public function test_concurrent_mark_paid_creates_only_one_settlement_payment(): void
    {
        $this->seedOrganization();
        $invoice = $this->makeDraft(10000);

        app(CurrentOrganization::class)->set($this->organization);
        app(IssuedInvoiceLifecycle::class)->issue($invoice, CarbonImmutable::parse('2026-08-01'));
        app(CurrentOrganization::class)->set(null);

        $invoiceId = $invoice->id;

        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->markPaid($fresh, CarbonImmutable::parse('2026-08-03'));
            },
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->markPaid($fresh, CarbonImmutable::parse('2026-08-03'));
            },
        ]);

        $succeeded = count(array_filter($errors, fn (string $e): bool => $e === ''));

        $this->assertSame(1, $succeeded, 'Uspět smí právě jeden markPaid. Chyby: '.implode(' | ', $errors));

        $fresh = IssuedInvoice::withoutGlobalScope('organization')->findOrFail($invoiceId);

        $this->assertSame(10000, (int) $fresh->paid_amount_minor, 'Zaplaceno nesmí překročit celkovou částku.');
        $this->assertSame(IssuedInvoiceStatus::Paid, $fresh->status);
        $this->assertSame(
            1,
            Payment::withoutGlobalScope('organization')
                ->where('payable_id', $invoiceId)
                ->where('payable_type', IssuedInvoice::class)
                ->count(),
            'Druhá doplatková platba nesmí vzniknout.',
        );
    }

    public function test_concurrent_issue_consumes_only_one_number(): void
    {
        $this->seedOrganization();
        $invoice = $this->makeDraft(5000);
        $invoiceId = $invoice->id;
        $seriesId = $invoice->number_series_id;

        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->issue($fresh, CarbonImmutable::parse('2026-08-01'));
            },
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->issue($fresh, CarbonImmutable::parse('2026-08-01'));
            },
        ]);

        $succeeded = count(array_filter($errors, fn (string $e): bool => $e === ''));

        $this->assertSame(1, $succeeded, 'Vystavit smí právě jeden proces. Chyby: '.implode(' | ', $errors));

        $fresh = IssuedInvoice::withoutGlobalScope('organization')->findOrFail($invoiceId);
        $series = InvoiceNumberSeries::withoutGlobalScope('organization')->findOrFail($seriesId);

        $this->assertSame(IssuedInvoiceStatus::Issued, $fresh->status);
        $this->assertSame('FV20260001', $fresh->invoice_number);
        $this->assertSame(2, (int) $series->next_number, 'Spotřebovat se smí jen jedno číslo.');
    }

    public function test_concurrent_issue_and_delete_cannot_remove_an_issued_invoice(): void
    {
        $this->seedOrganization();
        $invoice = $this->makeDraft(5000);
        $invoiceId = $invoice->id;

        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->issue($fresh, CarbonImmutable::parse('2026-08-01'));
            },
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->deleteDraft($fresh);
            },
        ]);

        $row = DB::table('issued_invoices')->where('id', $invoiceId)->first();

        if ($errors[0] === '') {
            // Vystavení uspělo → faktura MUSÍ existovat a být vystavená.
            $this->assertNotNull($row, 'Vystavená faktura nesmí být smazána.');
            $this->assertSame(IssuedInvoiceStatus::Issued->value, $row->status);
            $this->assertNotNull($row->invoice_number);
        } else {
            // Smazání proběhlo dřív → faktura neexistuje a číslo se nespotřebovalo.
            $this->assertNull($row);
        }

        // V žádném případě nesmí zůstat vystavená faktura bez čísla ani
        // smazaná faktura s přiděleným číslem.
        $this->assertFalse(
            $row !== null && $row->status === IssuedInvoiceStatus::Issued->value && $row->invoice_number === null,
            'Vystavená faktura bez čísla je nekonzistentní stav.',
        );
    }

    public function test_conflicting_transitions_are_serialised(): void
    {
        $this->seedOrganization();
        $invoice = $this->makeDraft(10000);

        app(CurrentOrganization::class)->set($this->organization);
        app(IssuedInvoiceLifecycle::class)->issue($invoice, CarbonImmutable::parse('2026-08-01'));
        app(CurrentOrganization::class)->set(null);

        $invoiceId = $invoice->id;

        // Storno vs. plná úhrada — protichůdné přechody.
        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->cancel($fresh);
            },
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->markPaid($fresh, CarbonImmutable::parse('2026-08-03'));
            },
        ]);

        $succeeded = count(array_filter($errors, fn (string $e): bool => $e === ''));

        $this->assertSame(1, $succeeded, 'Uspět smí právě jeden přechod. Chyby: '.implode(' | ', $errors));

        $fresh = IssuedInvoice::withoutGlobalScope('organization')->findOrFail($invoiceId);

        $this->assertContains(
            $fresh->status,
            [IssuedInvoiceStatus::Cancelled, IssuedInvoiceStatus::Paid],
        );

        if ($fresh->status === IssuedInvoiceStatus::Cancelled) {
            $this->assertSame(0, (int) $fresh->paid_amount_minor, 'Stornovaná faktura nesmí mít úhradu.');
        }
    }
}
