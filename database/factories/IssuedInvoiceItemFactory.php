<?php

namespace Database\Factories;

use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssuedInvoiceItem>
 */
class IssuedInvoiceItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'issued_invoice_id' => IssuedInvoice::factory(),
            'organization_id' => fn (array $attributes) => IssuedInvoice::query()
                ->withoutGlobalScope('organization')
                ->findOrFail($attributes['issued_invoice_id'])
                ->organization_id,
            'position' => 1,
            'description' => fake()->sentence(3),
            'quantity' => '1.000',
            'unit' => 'ks',
            'unit_price_minor' => 100000,
            'vat_rate' => '21.00',
            'line_subtotal_minor' => 0,
            'line_vat_minor' => 0,
            'line_total_minor' => 0,
        ];
    }
}
