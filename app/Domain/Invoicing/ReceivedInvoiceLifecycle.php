<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Audit\AuditLogger;
use App\Enums\ReceivedInvoiceStatus;
use App\Models\ReceivedInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Jediné místo, kde se mění stavy přijatých faktur (viz INVOICE_LIFECYCLE.md).
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

    public function __construct(
        private readonly AuditLogger $auditLogger,
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
        $this->assertTransition($invoice->status, ReceivedInvoiceStatus::Paid);

        DB::transaction(function () use ($invoice, $paidOn): void {
            $previousStatus = $invoice->status;

            $invoice->payments()->create([
                'organization_id' => $invoice->organization_id,
                'amount_minor' => $invoice->total_minor,
                'currency' => $invoice->currency,
                'paid_on' => $paidOn,
            ]);

            $invoice->forceFill([
                'status' => ReceivedInvoiceStatus::Paid,
                'paid_at' => $paidOn,
            ])->save();

            $this->auditLogger->log('invoice.payment_registered', $invoice, [
                'amount_minor' => $invoice->total_minor,
                'paid_on' => $paidOn->toDateString(),
                'status' => ['from' => $previousStatus->value, 'to' => ReceivedInvoiceStatus::Paid->value],
            ]);
        });
    }

    private function transition(ReceivedInvoice $invoice, ReceivedInvoiceStatus $to): void
    {
        $this->assertTransition($invoice->status, $to);

        DB::transaction(function () use ($invoice, $to): void {
            $previousStatus = $invoice->status;

            $invoice->forceFill(['status' => $to])->save();

            $this->auditLogger->log('invoice.status_changed', $invoice, [
                'status' => ['from' => $previousStatus->value, 'to' => $to->value],
            ]);
        });
    }

    private function assertTransition(ReceivedInvoiceStatus $from, ReceivedInvoiceStatus $to): void
    {
        if (! in_array($to->value, self::TRANSITIONS[$from->value], true)) {
            throw InvalidStateTransition::between($from->value, $to->value);
        }
    }
}
