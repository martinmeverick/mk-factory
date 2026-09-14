<?php

namespace Database\Factories;

use App\Enums\ContactType;
use App\Enums\IssuedInvoiceStatus;
use App\Enums\VatRegime;
use App\Models\Contact;
use App\Models\IssuedInvoice;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssuedInvoice>
 */
class IssuedInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'number_series_id' => null,
            // Odběratel patří stejné organizaci (klíč organization_id je
            // v definici dřív, takže je při vyhodnocení closure už vytvořen).
            'contact_id' => fn (array $attributes) => Contact::factory()->create([
                'organization_id' => $attributes['organization_id'],
                'type' => ContactType::Customer,
            ])->id,
            'project_id' => null,
            'bank_account_id' => null,
            'status' => IssuedInvoiceStatus::Draft,
            'invoice_number' => null,
            'variable_symbol' => null,
            'issue_date' => today(),
            'due_date' => today()->addDays(14),
            'tax_date' => null,
            'currency' => 'CZK',
            'subtotal_minor' => 100000,
            'vat_total_minor' => 21000,
            'total_minor' => 121000,
            'paid_amount_minor' => 0,
            'vat_regime' => VatRegime::Standard,
            'margin_vat_rate' => null,
            'margin_acquisition_total_minor' => 0,
            'margin_gross_minor' => 0,
            'margin_vat_minor' => 0,
            'margin_base_minor' => 0,
            'note' => null,
            'internal_note' => null,
            'footer_text' => null,
            'supplier_snapshot' => null,
            'customer_snapshot' => null,
            'bank_account_snapshot' => null,
            'issued_at' => null,
            'paid_at' => null,
            'cancelled_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state([
            'status' => IssuedInvoiceStatus::Draft,
            'invoice_number' => null,
            'issued_at' => null,
        ]);
    }

    public function issued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IssuedInvoiceStatus::Issued,
            'invoice_number' => fake()->unique()->numerify('FV2026####'),
            'variable_symbol' => fake()->unique()->numerify('2026####'),
            'issued_at' => now(),
        ]);
    }

    /**
     * Zvláštní režim - použité zboží (§ 90): interní sazba 21 %, položky
     * dostávají pořizovací cenu přes IssuedInvoiceItemFactory::usedGoods().
     * Součty se nastavují na 0 — dopočítá je InvoiceTotalsCalculator.
     */
    public function usedGoodsMargin(): static
    {
        return $this->state([
            'vat_regime' => VatRegime::UsedGoodsMargin,
            'margin_vat_rate' => '21.00',
            'subtotal_minor' => 0,
            'vat_total_minor' => 0,
            'total_minor' => 0,
        ]);
    }

    public function paid(): static
    {
        return $this->issued()->state(fn (array $attributes) => [
            'status' => IssuedInvoiceStatus::Paid,
            'paid_amount_minor' => $attributes['total_minor'],
            'paid_at' => now(),
        ]);
    }

    public function overdue(): static
    {
        return $this->issued()->state([
            'issue_date' => today()->subDays(30),
            'due_date' => today()->subDays(10),
        ]);
    }
}
