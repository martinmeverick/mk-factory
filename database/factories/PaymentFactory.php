<?php

namespace Database\Factories;

use App\Models\IssuedInvoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            // payable_id musí být v poli dřív než organization_id,
            // aby byl při vyhodnocení closure už vytvořen.
            'payable_type' => IssuedInvoice::class,
            'payable_id' => IssuedInvoice::factory(),
            'organization_id' => fn (array $attributes) => IssuedInvoice::query()
                ->withoutGlobalScope('organization')
                ->findOrFail($attributes['payable_id'])
                ->organization_id,
            'amount_minor' => 10000,
            'currency' => 'CZK',
            'paid_on' => today(),
            'note' => null,
        ];
    }
}
