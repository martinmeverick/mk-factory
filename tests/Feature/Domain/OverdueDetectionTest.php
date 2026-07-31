<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\IssuedInvoiceStatus;
use App\Enums\ReceivedInvoiceStatus;
use App\Models\IssuedInvoice;
use App\Models\ReceivedInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverdueDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_issued_invoice_past_due_is_overdue(): void
    {
        $invoice = IssuedInvoice::factory()->overdue()->create();

        $this->assertTrue(IssuedInvoice::overdue()->pluck('id')->contains($invoice->id));
        $this->assertSame('overdue', $invoice->display_status);
    }

    public function test_partially_paid_invoice_past_due_is_overdue(): void
    {
        $invoice = IssuedInvoice::factory()->overdue()->create([
            'status' => IssuedInvoiceStatus::PartiallyPaid,
            'paid_amount_minor' => 1000,
        ]);

        $this->assertTrue(IssuedInvoice::overdue()->pluck('id')->contains($invoice->id));
        $this->assertSame('overdue', $invoice->display_status);
    }

    public function test_issued_invoice_before_due_date_is_not_overdue(): void
    {
        $invoice = IssuedInvoice::factory()->issued()->create([
            'due_date' => today()->addDays(5),
        ]);

        $this->assertFalse(IssuedInvoice::overdue()->pluck('id')->contains($invoice->id));
        $this->assertSame('issued', $invoice->display_status);
    }

    public function test_invoice_due_today_is_not_overdue(): void
    {
        $invoice = IssuedInvoice::factory()->issued()->create([
            'due_date' => today(),
        ]);

        $this->assertFalse(IssuedInvoice::overdue()->pluck('id')->contains($invoice->id));
    }

    public function test_paid_invoice_past_due_is_not_overdue(): void
    {
        $invoice = IssuedInvoice::factory()->paid()->create([
            'due_date' => today()->subDays(10),
        ]);

        $this->assertFalse(IssuedInvoice::overdue()->pluck('id')->contains($invoice->id));
        $this->assertSame('paid', $invoice->display_status);
    }

    public function test_cancelled_invoice_past_due_is_not_overdue(): void
    {
        $invoice = IssuedInvoice::factory()->issued()->create([
            'status' => IssuedInvoiceStatus::Cancelled,
            'due_date' => today()->subDays(10),
            'cancelled_at' => now(),
        ]);

        $this->assertFalse(IssuedInvoice::overdue()->pluck('id')->contains($invoice->id));
        $this->assertSame('cancelled', $invoice->display_status);
    }

    public function test_draft_past_due_is_not_overdue(): void
    {
        $invoice = IssuedInvoice::factory()->draft()->create([
            'due_date' => today()->subDays(10),
        ]);

        $this->assertFalse(IssuedInvoice::overdue()->pluck('id')->contains($invoice->id));
        $this->assertSame('draft', $invoice->display_status);
    }

    public function test_received_invoice_past_due_is_overdue(): void
    {
        $received = ReceivedInvoice::factory()->overdue()->create();
        $approved = ReceivedInvoice::factory()->approved()->overdue()->create();

        $overdueIds = ReceivedInvoice::overdue()->pluck('id');
        $this->assertTrue($overdueIds->contains($received->id));
        $this->assertTrue($overdueIds->contains($approved->id));
        $this->assertSame('overdue', $received->display_status);
        $this->assertSame('overdue', $approved->display_status);
    }

    public function test_received_invoice_before_due_date_is_not_overdue(): void
    {
        $invoice = ReceivedInvoice::factory()->create([
            'due_date' => today()->addDays(5),
        ]);

        $this->assertFalse(ReceivedInvoice::overdue()->pluck('id')->contains($invoice->id));
        $this->assertSame('received', $invoice->display_status);
    }

    public function test_paid_or_rejected_received_invoice_past_due_is_not_overdue(): void
    {
        $paid = ReceivedInvoice::factory()->paid()->create([
            'due_date' => today()->subDays(10),
        ]);
        $rejected = ReceivedInvoice::factory()->rejected()->create([
            'due_date' => today()->subDays(10),
        ]);

        $overdueIds = ReceivedInvoice::overdue()->pluck('id');
        $this->assertFalse($overdueIds->contains($paid->id));
        $this->assertFalse($overdueIds->contains($rejected->id));
        $this->assertSame('paid', $paid->display_status);
        $this->assertSame('rejected', $rejected->display_status);
    }

    public function test_received_invoice_without_due_date_is_not_overdue(): void
    {
        $invoice = ReceivedInvoice::factory()->create(['due_date' => null]);

        $this->assertFalse(ReceivedInvoice::overdue()->pluck('id')->contains($invoice->id));
        $this->assertSame('received', $invoice->display_status);
    }
}
