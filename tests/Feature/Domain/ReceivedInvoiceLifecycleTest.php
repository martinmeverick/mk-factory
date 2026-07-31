<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Invoicing\InvalidStateTransition;
use App\Domain\Invoicing\ReceivedInvoiceLifecycle;
use App\Enums\ReceivedInvoiceStatus;
use App\Models\AuditLog;
use App\Models\ReceivedInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceivedInvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private ReceivedInvoiceLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = app(ReceivedInvoiceLifecycle::class);
    }

    public function test_approve_received_invoice(): void
    {
        $invoice = ReceivedInvoice::factory()->create();

        $this->lifecycle->approve($invoice);

        $this->assertSame(ReceivedInvoiceStatus::Approved, $invoice->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'invoice.status_changed')->count());
    }

    public function test_reject_received_and_approved_invoice(): void
    {
        $received = ReceivedInvoice::factory()->create();
        $approved = ReceivedInvoice::factory()->approved()->create();

        $this->lifecycle->reject($received);
        $this->lifecycle->reject($approved);

        $this->assertSame(ReceivedInvoiceStatus::Rejected, $received->fresh()->status);
        $this->assertSame(ReceivedInvoiceStatus::Rejected, $approved->fresh()->status);
    }

    public function test_mark_paid_sets_paid_at_and_creates_payment(): void
    {
        $invoice = ReceivedInvoice::factory()->approved()->create(['total_minor' => 60500]);
        $paidOn = CarbonImmutable::today()->subDays(2);

        $this->lifecycle->markPaid($invoice, $paidOn);
        $invoice->refresh();

        $this->assertSame(ReceivedInvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame($paidOn->toDateString(), $invoice->paid_at->toDateString());

        $payment = $invoice->payments()->first();
        $this->assertNotNull($payment);
        $this->assertSame(60500, $payment->amount_minor);
        $this->assertSame($paidOn->toDateString(), $payment->paid_on->toDateString());

        $this->assertSame(1, AuditLog::where('action', 'invoice.payment_registered')->count());
    }

    public function test_mark_paid_directly_from_received(): void
    {
        $invoice = ReceivedInvoice::factory()->create();

        $this->lifecycle->markPaid($invoice, CarbonImmutable::today());

        $this->assertSame(ReceivedInvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_approve_paid_invoice_throws(): void
    {
        $invoice = ReceivedInvoice::factory()->paid()->create();

        $this->expectException(InvalidStateTransition::class);

        $this->lifecycle->approve($invoice);
    }

    public function test_approve_approved_invoice_throws(): void
    {
        $invoice = ReceivedInvoice::factory()->approved()->create();

        $this->expectException(InvalidStateTransition::class);

        $this->lifecycle->approve($invoice);
    }

    public function test_reject_rejected_invoice_throws(): void
    {
        $invoice = ReceivedInvoice::factory()->rejected()->create();

        $this->expectException(InvalidStateTransition::class);

        $this->lifecycle->reject($invoice);
    }

    public function test_mark_paid_rejected_invoice_throws(): void
    {
        $invoice = ReceivedInvoice::factory()->rejected()->create();

        $this->expectException(InvalidStateTransition::class);

        $this->lifecycle->markPaid($invoice, CarbonImmutable::today());
    }

    public function test_mark_paid_paid_invoice_throws(): void
    {
        $invoice = ReceivedInvoice::factory()->paid()->create();

        $this->expectException(InvalidStateTransition::class);

        $this->lifecycle->markPaid($invoice, CarbonImmutable::today());
    }
}
