<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Invoicing\InvalidStateTransition;
use App\Domain\Invoicing\InvoiceNotIssuable;
use App\Domain\Invoicing\IssuedInvoiceLifecycle;
use App\Enums\IssuedInvoiceStatus;
use App\Models\AuditLog;
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

class IssuedInvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private IssuedInvoiceLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = app(IssuedInvoiceLifecycle::class);
    }

    private function makeDraft(bool $withItems = true, array $overrides = []): IssuedInvoice
    {
        $organization = Organization::factory()->create();

        $bankAccount = BankAccount::factory()->create([
            'organization_id' => $organization->id,
            'is_default' => true,
            'iban' => 'CZ1801000000000123456789',
        ]);

        $series = InvoiceNumberSeries::factory()->create([
            'organization_id' => $organization->id,
        ]);

        OrganizationSettings::factory()->create([
            'organization_id' => $organization->id,
            'default_bank_account_id' => $bankAccount->id,
            'default_number_series_id' => $series->id,
            'default_due_days' => 14,
            'invoice_footer_text' => 'Patička faktury.',
        ]);

        $contact = Contact::factory()->create(['organization_id' => $organization->id]);

        $invoice = IssuedInvoice::factory()->draft()->create([
            'organization_id' => $organization->id,
            'contact_id' => $contact->id,
            'number_series_id' => $series->id,
            'bank_account_id' => $bankAccount->id,
            'due_date' => null,
            'subtotal_minor' => 0,
            'vat_total_minor' => 0,
            'total_minor' => 0,
            ...$overrides,
        ]);

        if ($withItems) {
            IssuedInvoiceItem::factory()->create([
                'issued_invoice_id' => $invoice->id,
                'organization_id' => $organization->id,
                'quantity' => '2.000',
                'unit_price_minor' => 50000,
                'vat_rate' => '21.00',
            ]);
        }

        return $invoice;
    }

    public function test_issue_assigns_number_symbol_snapshots_totals_and_audit(): void
    {
        $invoice = $this->makeDraft();

        $this->lifecycle->issue($invoice);
        $invoice->refresh();

        $this->assertSame(IssuedInvoiceStatus::Issued, $invoice->status);
        $this->assertSame('FV20260001', $invoice->invoice_number);
        $this->assertSame('20260001', $invoice->variable_symbol);
        $this->assertNotNull($invoice->issued_at);

        // Datum vystavení = dnešek, splatnost ze settings (14 dní).
        $this->assertSame(today()->toDateString(), $invoice->issue_date->toDateString());
        $this->assertSame(today()->addDays(14)->toDateString(), $invoice->due_date->toDateString());

        // Součty přepočtené kalkulátorem: 2 × 500 Kč + 21 %.
        $this->assertSame(100000, $invoice->subtotal_minor);
        $this->assertSame(21000, $invoice->vat_total_minor);
        $this->assertSame(121000, $invoice->total_minor);

        // Snapshoty stran a účtu.
        $organization = $invoice->organization;
        $this->assertSame($organization->name, $invoice->supplier_snapshot['name']);
        $this->assertSame($invoice->contact->name, $invoice->customer_snapshot['name']);
        $this->assertSame('CZ1801000000000123456789', $invoice->bank_account_snapshot['iban']);
        $this->assertSame('Patička faktury.', $invoice->footer_text);

        // Audit log.
        $log = AuditLog::where('action', 'invoice.issued')->first();
        $this->assertNotNull($log);
        $this->assertSame($invoice->id, $log->subject_id);
        $this->assertSame(IssuedInvoice::class, $log->subject_type);
        $this->assertSame('FV20260001', $log->changes['invoice_number']);
    }

    public function test_issue_accepts_explicit_issue_date(): void
    {
        $invoice = $this->makeDraft();

        $this->lifecycle->issue($invoice, CarbonImmutable::parse('2026-07-01'));
        $invoice->refresh();

        $this->assertSame('2026-07-01', $invoice->issue_date->toDateString());
        $this->assertSame('2026-07-15', $invoice->due_date->toDateString());
    }

    public function test_issue_keeps_preset_due_date(): void
    {
        $invoice = $this->makeDraft(overrides: ['due_date' => '2026-12-31']);

        $this->lifecycle->issue($invoice);

        $this->assertSame('2026-12-31', $invoice->fresh()->due_date->toDateString());
    }

    public function test_issue_twice_throws_invalid_transition(): void
    {
        $invoice = $this->makeDraft();
        $this->lifecycle->issue($invoice);

        $this->expectException(InvalidStateTransition::class);

        $this->lifecycle->issue($invoice->fresh());
    }

    public function test_issue_without_items_throws(): void
    {
        $invoice = $this->makeDraft(withItems: false);

        $this->expectException(InvoiceNotIssuable::class);

        $this->lifecycle->issue($invoice);
    }

    public function test_issue_without_bank_account_throws_when_organization_has_one(): void
    {
        $invoice = $this->makeDraft(overrides: ['bank_account_id' => null]);

        $this->expectException(InvoiceNotIssuable::class);

        $this->lifecycle->issue($invoice);
    }

    public function test_register_payment_partial_then_full(): void
    {
        $invoice = $this->makeDraft();
        $this->lifecycle->issue($invoice);
        $invoice->refresh();

        $this->lifecycle->registerPayment($invoice, 50000, CarbonImmutable::today());
        $invoice->refresh();

        $this->assertSame(IssuedInvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertSame(50000, $invoice->paid_amount_minor);
        $this->assertNull($invoice->paid_at);
        $this->assertSame(1, $invoice->payments()->count());

        $this->lifecycle->registerPayment($invoice, 71000, CarbonImmutable::today());
        $invoice->refresh();

        $this->assertSame(IssuedInvoiceStatus::Paid, $invoice->status);
        $this->assertSame(121000, $invoice->paid_amount_minor);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame(2, $invoice->payments()->count());

        $this->assertSame(2, AuditLog::where('action', 'invoice.payment_registered')->count());
    }

    public function test_register_payment_on_draft_throws(): void
    {
        $invoice = $this->makeDraft();

        $this->expectException(InvalidStateTransition::class);

        $this->lifecycle->registerPayment($invoice, 1000, CarbonImmutable::today());
    }

    public function test_mark_paid_registers_remaining_amount(): void
    {
        $invoice = $this->makeDraft();
        $this->lifecycle->issue($invoice);
        $invoice->refresh();

        $this->lifecycle->markPaid($invoice, CarbonImmutable::today());
        $invoice->refresh();

        $this->assertSame(IssuedInvoiceStatus::Paid, $invoice->status);
        $this->assertSame($invoice->total_minor, $invoice->paid_amount_minor);
        $this->assertSame(121000, $invoice->payments()->first()->amount_minor);
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_cancel_issued_invoice_without_payments(): void
    {
        $invoice = $this->makeDraft();
        $this->lifecycle->issue($invoice);
        $invoice->refresh();

        $this->lifecycle->cancel($invoice);
        $invoice->refresh();

        $this->assertSame(IssuedInvoiceStatus::Cancelled, $invoice->status);
        $this->assertNotNull($invoice->cancelled_at);
        // Číslo zůstává spotřebované.
        $this->assertSame('FV20260001', $invoice->invoice_number);
        $this->assertSame(1, AuditLog::where('action', 'invoice.cancelled')->count());
    }

    public function test_cancel_with_recorded_payment_throws(): void
    {
        $invoice = $this->makeDraft();
        $this->lifecycle->issue($invoice);
        $invoice->refresh();

        // Platba zaevidovaná mimo lifecycle (pojistka na přímý zápis).
        $invoice->payments()->create([
            'organization_id' => $invoice->organization_id,
            'amount_minor' => 1000,
            'currency' => 'CZK',
            'paid_on' => today(),
        ]);

        $this->expectException(InvalidStateTransition::class);

        $this->lifecycle->cancel($invoice);
    }

    public function test_cancel_draft_throws(): void
    {
        $invoice = $this->makeDraft();

        $this->expectException(InvalidStateTransition::class);

        $this->lifecycle->cancel($invoice);
    }

    public function test_cancel_paid_invoice_throws(): void
    {
        $invoice = $this->makeDraft();
        $this->lifecycle->issue($invoice);
        $invoice->refresh();
        $this->lifecycle->markPaid($invoice, CarbonImmutable::today());
        $invoice->refresh();

        $this->expectException(InvalidStateTransition::class);

        $this->lifecycle->cancel($invoice);
    }
}
