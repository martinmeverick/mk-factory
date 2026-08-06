<?php

namespace App\Models;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Enums\ReceivedInvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceivedInvoiceAttachment extends Model
{
    /** @use HasFactory<\Database\Factories\ReceivedInvoiceAttachmentFactory> */
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
        // Přílohy uhrazené faktury jsou součást dokladu — nelze je přidat,
        // změnit ani smazat. Stav rodiče se čte z DATABÁZE, ne z (možná
        // zastaralé) načtené relace; stejný vzor jako IssuedInvoiceItem.
        $guard = function (self $attachment): void {
            $invoiceId = $attachment->received_invoice_id;

            if ($invoiceId === null) {
                return;
            }

            $status = ReceivedInvoice::query()
                ->withoutGlobalScope('organization')
                ->whereKey($invoiceId)
                ->value('status');

            $status = $status instanceof ReceivedInvoiceStatus ? $status->value : $status;

            if ($status === ReceivedInvoiceStatus::Paid->value) {
                throw ImmutableInvoiceViolation::forPaidReceivedAttachments((int) $invoiceId);
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function receivedInvoice(): BelongsTo
    {
        return $this->belongsTo(ReceivedInvoice::class);
    }
}
