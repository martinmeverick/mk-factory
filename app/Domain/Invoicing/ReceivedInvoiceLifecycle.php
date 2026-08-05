<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ReceivedInvoiceStatus;
use App\Models\ReceivedInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Jediné místo, kde se mění stavy přijatých faktur (viz INVOICE_LIFECYCLE.md).
 *
 * Stejný vzor jako u vydaných faktur: transakce → tenant-scoped dotaz →
 * lockForUpdate() → kontrola stavu nad ZAMČENÝM řádkem → změna → audit.
 * Souběžné protichůdné přechody se tím serializují a druhý je odmítnut
 * podle skutečného aktuálního stavu.
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

            $locked->forceFill([
                'status' => ReceivedInvoiceStatus::Paid,
                'paid_at' => $paidOn,
            ])->save();

            $this->auditLogger->log('invoice.payment_registered', $locked, [
                'amount_minor' => $locked->total_minor,
                'paid_on' => $paidOn->toDateString(),
                'status' => ['from' => $previousStatus->value, 'to' => ReceivedInvoiceStatus::Paid->value],
            ]);
        });
    }

    private function transition(ReceivedInvoice $invoice, ReceivedInvoiceStatus $to): void
    {
        $this->mutate($invoice, function (ReceivedInvoice $locked) use ($to): void {
            $this->assertTransition($locked->status, $to);

            $previousStatus = $locked->status;

            $locked->forceFill(['status' => $to])->save();

            $this->auditLogger->log('invoice.status_changed', $locked, [
                'status' => ['from' => $previousStatus->value, 'to' => $to->value],
            ]);
        });
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

            $invoice->setRawAttributes($locked->getAttributes(), true);
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
