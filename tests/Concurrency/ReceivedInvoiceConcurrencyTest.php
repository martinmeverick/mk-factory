<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Domain\Invoicing\ReceivedInvoiceLifecycle;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ContactType;
use App\Enums\ReceivedInvoiceStatus;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\ReceivedInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * RE-REVIEW: přijaté faktury musí mít stejné concurrency záruky jako
 * vydané — markPaid, update a delete se serializují zámkem řádku
 * a o výsledku rozhoduje AKTUÁLNÍ stav, ne stav načtené instance.
 */
class ReceivedInvoiceConcurrencyTest extends ConcurrencyTestCase
{
    private Organization $organization;

    private function seedInvoice(): ReceivedInvoice
    {
        $this->organization = Organization::withoutGlobalScope('organization')->create([
            'name' => 'Souběh přijatých s.r.o.',
            'country' => 'CZ',
        ]);

        $supplier = Contact::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'type' => ContactType::Supplier,
            'name' => 'Dodavatel '.uniqid(),
            'country' => 'CZ',
        ]);

        return ReceivedInvoice::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'contact_id' => $supplier->id,
            'supplier_invoice_number' => '2026001',
            'issue_date' => '2026-08-01',
            'received_date' => '2026-08-02',
            'due_date' => '2026-08-15',
            'currency' => 'CZK',
            'total_minor' => 60500,
            'status' => ReceivedInvoiceStatus::Received,
        ]);
    }

    /**
     * Uvnitř potomka: čerstvý tenant context a čerstvá instance faktury.
     *
     * @return array{0: ReceivedInvoiceLifecycle, 1: ReceivedInvoice}
     */
    private function lifecycleFor(int $invoiceId): array
    {
        app(CurrentOrganization::class)->set(
            Organization::withoutGlobalScope('organization')->findOrFail($this->organization->id)
        );

        $invoice = ReceivedInvoice::withoutGlobalScope('organization')->findOrFail($invoiceId);

        return [app(ReceivedInvoiceLifecycle::class), $invoice];
    }

    public function test_concurrent_mark_paid_creates_only_one_payment(): void
    {
        $invoice = $this->seedInvoice();
        $invoiceId = $invoice->id;

        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->markPaid($fresh, CarbonImmutable::parse('2026-08-05'));
            },
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->markPaid($fresh, CarbonImmutable::parse('2026-08-05'));
            },
        ]);

        $succeeded = count(array_filter($errors, fn (string $e): bool => $e === ''));

        $this->assertSame(1, $succeeded, 'Uspět smí právě jeden markPaid. Chyby: '.implode(' | ', $errors));

        $fresh = ReceivedInvoice::withoutGlobalScope('organization')->findOrFail($invoiceId);

        $this->assertSame(ReceivedInvoiceStatus::Paid, $fresh->status);
        $this->assertSame(
            1,
            Payment::withoutGlobalScope('organization')
                ->where('payable_id', $invoiceId)
                ->where('payable_type', ReceivedInvoice::class)
                ->count(),
            'Druhá platba nesmí vzniknout.',
        );
    }

    public function test_mark_paid_and_update_end_consistently(): void
    {
        $invoice = $this->seedInvoice();
        $invoiceId = $invoice->id;

        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->markPaid($fresh, CarbonImmutable::parse('2026-08-05'));
            },
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->updateDetails($fresh, ['due_date' => '2027-01-01', 'total_minor' => 99999]);
            },
        ]);

        $this->assertSame('', $errors[0], 'markPaid musí projít: '.$errors[0]);

        $fresh = ReceivedInvoice::withoutGlobalScope('organization')->findOrFail($invoiceId);

        // Faktura je v každém případě uhrazená s právě jednou platbou.
        $this->assertSame(ReceivedInvoiceStatus::Paid, $fresh->status);
        $paymentCount = Payment::withoutGlobalScope('organization')->where('payable_id', $invoiceId)->count();
        $this->assertSame(1, $paymentCount);

        if ($errors[1] === '') {
            // Update stihl proběhnout PŘED markPaid — změny jsou zapsané
            // a platba zněla už na novou částku.
            $this->assertSame('2027-01-01', $fresh->due_date->toDateString());
            $this->assertSame(99999, (int) $fresh->total_minor);
        } else {
            // markPaid vyhrál — stale update byl odmítnut podle aktuálního
            // stavu zamčeného řádku a nezměnil NIC.
            $this->assertStringContainsString('InvalidStateTransition', $errors[1]);
            $this->assertSame('2026-08-15', $fresh->due_date->toDateString());
            $this->assertSame(60500, (int) $fresh->total_minor);
        }
    }

    public function test_mark_paid_and_destroy_end_consistently(): void
    {
        $invoice = $this->seedInvoice();
        $invoiceId = $invoice->id;

        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->markPaid($fresh, CarbonImmutable::parse('2026-08-05'));
            },
            function (WorkerBarrier $barrier) use ($invoiceId): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);
                $barrier();
                $lifecycle->delete($fresh);
            },
        ]);

        $row = DB::table('received_invoices')->where('id', $invoiceId)->first();
        $paymentCount = Payment::withoutGlobalScope('organization')->where('payable_id', $invoiceId)->count();

        if ($row !== null) {
            // markPaid vyhrál → faktura je uhrazená, platba existuje
            // a smazání bylo odmítnuto podle aktuálního stavu.
            $this->assertSame('', $errors[0], 'markPaid měl projít: '.$errors[0]);
            $this->assertStringContainsString('InvalidStateTransition', $errors[1]);
            $this->assertSame(ReceivedInvoiceStatus::Paid->value, $row->status);
            $this->assertSame(1, $paymentCount);
        } else {
            // Smazání proběhlo dřív → markPaid nenašel řádek a NEVZNIKLA
            // žádná platba k neexistující faktuře.
            $this->assertSame('', $errors[1], 'delete měl projít: '.$errors[1]);
            $this->assertStringContainsString('InvoiceNotFound', $errors[0]);
            $this->assertSame(0, $paymentCount);
        }

        // V žádném případě nesmí existovat uhrazená smazaná faktura ani
        // osiřelá platba.
        $this->assertFalse($row === null && $paymentCount > 0, 'Platba bez faktury je nekonzistentní stav.');
    }
}
