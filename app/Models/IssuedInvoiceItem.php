<?php

namespace App\Models;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Domain\Tenancy\BelongsToOrganization;
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
            // Zvláštní režim - použité zboží: interní pořizovací cena a přirážka.
            'acquisition_unit_price_minor' => 'integer',
            'line_acquisition_minor' => 'integer',
            'line_margin_gross_minor' => 'integer',
            'line_margin_vat_minor' => 'integer',
            'line_margin_base_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Položky lze měnit jen dokud je rodičovská faktura koncept.
        $guard = function (self $item): void {
            $invoice = $item->relationLoaded('invoice')
                ? $item->invoice
                : $item->invoice()->withoutGlobalScope('organization')->first();

            if ($invoice !== null && ! $invoice->isEditable()) {
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
