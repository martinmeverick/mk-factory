<?php

namespace App\Models;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Enums\ReceivedInvoiceStatus;
use Database\Factories\ReceivedInvoiceAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceivedInvoiceAttachment extends Model
{
    /** @use HasFactory<ReceivedInvoiceAttachmentFactory> */
    use BelongsToOrganization, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $attachment): void {
            self::assertParentIsNotFinal($attachment->received_invoice_id);
        });

        static::updating(function (self $attachment): void {
            // Vlastnická vazba je po vytvoření NEMĚNNÁ — jinak by šlo
            // přílohu uhrazené faktury „přestěhovat“ pod nefinální doklad
            // a guard by se pak ptal na stav toho nefinálního.
            if ($attachment->isDirty('received_invoice_id')) {
                throw ImmutableInvoiceViolation::forReparenting('Přílohu faktury', 'received_invoice_id');
            }

            if ($attachment->isDirty('organization_id')) {
                throw ImmutableInvoiceViolation::forChildTenantChange('Přílohu faktury');
            }

            self::assertParentIsNotFinal($attachment->getOriginal('received_invoice_id'));
        });

        static::deleting(function (self $attachment): void {
            self::assertParentIsNotFinal(
                $attachment->getOriginal('received_invoice_id') ?? $attachment->received_invoice_id
            );
        });
    }

    /**
     * Přílohy faktury ve FINÁLNÍM stavu (uhrazená i zamítnutá) jsou součást
     * dokladu — nelze je přidat, změnit ani smazat. Finalitu určuje jediný
     * zdroj pravdy ReceivedInvoice::FINAL_STATUSES a stav se čte z DATABÁZE,
     * ne z (možná zastaralé) načtené relace.
     */
    private static function assertParentIsNotFinal(mixed $invoiceId): void
    {
        if ($invoiceId === null) {
            return;
        }

        $status = ReceivedInvoice::query()
            ->withoutGlobalScope('organization')
            ->whereKey($invoiceId)
            ->value('status');

        if ($status === null) {
            return;
        }

        $status = $status instanceof ReceivedInvoiceStatus
            ? $status
            : ReceivedInvoiceStatus::from((string) $status);

        if (ReceivedInvoice::isFinalStatus($status)) {
            throw ImmutableInvoiceViolation::forFinalReceivedAttachments((int) $invoiceId);
        }
    }

    public function receivedInvoice(): BelongsTo
    {
        return $this->belongsTo(ReceivedInvoice::class);
    }
}
