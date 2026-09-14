<?php

namespace App\Models;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Enums\IssuedInvoiceStatus;
use Database\Factories\IssuedInvoiceItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuedInvoiceItem extends Model
{
    /** @use HasFactory<IssuedInvoiceItemFactory> */
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
        // Vznik položky se posuzuje podle faktury, pod kterou má vzniknout.
        static::creating(function (self $item): void {
            self::assertParentIsDraft($item->issued_invoice_id);
        });

        static::updating(function (self $item): void {
            // Vlastnická vazba je po vytvoření NEMĚNNÁ. Bez toho by šlo
            // položku vystavené faktury přepsat tak, že se současně přesune
            // pod koncept: guard by se pak ptal na stav konceptu, změnu
            // povolil a historický doklad by přišel o položku.
            if ($item->isDirty('issued_invoice_id')) {
                throw ImmutableInvoiceViolation::forReparenting('Položku faktury', 'issued_invoice_id');
            }

            if ($item->isDirty('organization_id')) {
                throw ImmutableInvoiceViolation::forChildTenantChange('Položku faktury');
            }

            // Rozhoduje PŮVODNÍ rodič podle stavu v DATABÁZI — ne hodnota
            // z (možná zastaralé) instance ani nová dirty hodnota.
            self::assertParentIsDraft($item->getOriginal('issued_invoice_id'));
        });

        static::deleting(function (self $item): void {
            self::assertParentIsDraft($item->getOriginal('issued_invoice_id') ?? $item->issued_invoice_id);
        });
    }

    /**
     * Položky lze měnit jen dokud je rodičovská faktura koncept. Stav se
     * čte z DATABÁZE, ne z načtené (možná zastaralé) relace.
     */
    private static function assertParentIsDraft(mixed $invoiceId): void
    {
        if ($invoiceId === null) {
            return;
        }

        $status = IssuedInvoice::query()
            ->withoutGlobalScope('organization')
            ->whereKey($invoiceId)
            ->value('status');

        $status = $status instanceof IssuedInvoiceStatus ? $status->value : $status;

        if ($status !== null && $status !== IssuedInvoiceStatus::Draft->value) {
            throw ImmutableInvoiceViolation::forItems(new IssuedInvoice(['id' => $invoiceId]));
        }
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(IssuedInvoice::class, 'issued_invoice_id');
    }
}
