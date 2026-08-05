<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Invoicing\InvoiceNotIssuable;
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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NÁLEZ 6: běžnou vydanou fakturu nelze vystavit s nulovým ani záporným
 * součtem — a neplatný doklad nesmí spotřebovat číslo z číselné řady.
 */
class InvoiceTotalGuardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private InvoiceNumberSeries $series;

    private IssuedInvoiceLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($this->organization);

        OrganizationSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'vat_payer' => false,
        ]);

        BankAccount::factory()->create([
            'organization_id' => $this->organization->id,
            'iban' => 'CZ1801000000000123456789',
        ]);

        $this->series = InvoiceNumberSeries::factory()->create([
            'organization_id' => $this->organization->id,
            'prefix' => 'FV',
            'year' => 2026,
            'next_number' => 1,
            'number_format' => '{PREFIX}{YEAR}{NUMBER:4}',
        ]);

        $this->lifecycle = app(IssuedInvoiceLifecycle::class);
    }

    /**
     * @param  list<int>  $unitPricesMinor
     */
    private function invoiceWithLines(array $unitPricesMinor): IssuedInvoice
    {
        $invoice = IssuedInvoice::factory()->draft()->create([
            'organization_id' => $this->organization->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $this->organization->id])->id,
            'number_series_id' => $this->series->id,
            'bank_account_id' => BankAccount::query()->firstOrFail()->id,
            'due_date' => '2026-08-15',
        ]);

        foreach ($unitPricesMinor as $index => $price) {
            IssuedInvoiceItem::factory()->create([
                'organization_id' => $this->organization->id,
                'issued_invoice_id' => $invoice->id,
                'position' => $index + 1,
                'quantity' => '1',
                'unit_price_minor' => $price,
                'vat_rate' => null,
            ]);
        }

        return $invoice;
    }

    private function assertNumberNotConsumed(IssuedInvoice $invoice): void
    {
        $this->assertSame(
            1,
            (int) $this->series->fresh()->next_number,
            'Neplatný doklad spotřeboval číslo z řady.',
        );
        $this->assertNull($invoice->fresh()->invoice_number);
        $this->assertSame(IssuedInvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_negative_total_cannot_be_issued(): void
    {
        $invoice = $this->invoiceWithLines([1000, -5000]);

        try {
            $this->lifecycle->issue($invoice, CarbonImmutable::parse('2026-08-01'));
            $this->fail('Faktura se záporným součtem byla vystavena.');
        } catch (InvoiceNotIssuable $e) {
            $this->assertStringContainsString('kladná', $e->getMessage());
        }

        $this->assertNumberNotConsumed($invoice);
    }

    public function test_zero_total_cannot_be_issued(): void
    {
        $invoice = $this->invoiceWithLines([5000, -5000]);

        try {
            $this->lifecycle->issue($invoice, CarbonImmutable::parse('2026-08-01'));
            $this->fail('Nulová faktura byla vystavena.');
        } catch (InvoiceNotIssuable $e) {
            $this->assertStringContainsString('kladná', $e->getMessage());
        }

        $this->assertNumberNotConsumed($invoice);
    }

    /**
     * Záporná položka jako sleva zůstává povolená, dokud je výsledek kladný.
     */
    public function test_discount_line_is_allowed_when_total_stays_positive(): void
    {
        $invoice = $this->invoiceWithLines([10000, -2500]);

        $this->lifecycle->issue($invoice, CarbonImmutable::parse('2026-08-01'));

        $fresh = $invoice->fresh();

        $this->assertSame(IssuedInvoiceStatus::Issued, $fresh->status);
        $this->assertSame(7500, (int) $fresh->total_minor);
        $this->assertSame('FV20260001', $fresh->invoice_number);
        $this->assertSame(2, (int) $this->series->fresh()->next_number);
    }

    /**
     * Nulový doklad končí validovanou doménovou chybou, ne neodchycenou
     * výjimkou až v markPaid().
     */
    public function test_zero_invoice_fails_at_issue_not_later_in_mark_paid(): void
    {
        $invoice = $this->invoiceWithLines([0]);

        $this->expectException(InvoiceNotIssuable::class);

        $this->lifecycle->issue($invoice, CarbonImmutable::parse('2026-08-01'));
    }

    public function test_failed_issue_rolls_back_everything(): void
    {
        $invoice = $this->invoiceWithLines([1000, -5000]);

        try {
            $this->lifecycle->issue($invoice, CarbonImmutable::parse('2026-08-01'));
        } catch (InvoiceNotIssuable) {
            // očekáváno
        }

        $this->assertNumberNotConsumed($invoice);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertNull($invoice->fresh()->supplier_snapshot);
        $this->assertNull($invoice->fresh()->issued_at);
    }
}
