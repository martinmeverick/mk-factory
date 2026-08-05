<?php

namespace App\Models;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Enums\IssuedInvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuedInvoiceItem extends Model
{
    /** @use HasFactory<\Database\Factories\IssuedInvoiceItemFactory> */
    use BelongsToOrganization, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:3',
            'vat_rate' => 'decimal:2',
            'unit_price_minor' => 'integer',
            'line_subtotal_minor' => 'integer',
            'line_vat_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Položky lze měnit jen dokud je rodičovská faktura koncept.
        // Stav se čte z DATABÁZE, ne z načtené (možná zastaralé) relace —
        // jinak by stará instance mohla měnit položky vystavené faktury.
        $guard = function (self $item): void {
            $invoiceId = $item->issued_invoice_id;

            if ($invoiceId === null) {
                return;
            }

            $status = IssuedInvoice::query()
                ->withoutGlobalScope('organization')
                ->whereKey($invoiceId)
                ->value('status');

            $status = $status instanceof IssuedInvoiceStatus ? $status->value : $status;

            if ($status !== null && $status !== IssuedInvoiceStatus::Draft->value) {
                $invoice = $item->relationLoaded('invoice') && $item->invoice !== null
                    ? $item->invoice
                    : new IssuedInvoice(['id' => $invoiceId]);

                throw ImmutableInvoiceViolation::forItems($invoice);
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(IssuedInvoice::class, 'issued_invoice_id');
    }
}
