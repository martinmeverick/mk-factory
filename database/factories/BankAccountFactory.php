<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Běžný účet',
            'account_number' => fake()->numerify('#########'),
            'bank_code' => fake()->randomElement(['0100', '0300', '0600', '0800', '2010']),
            'iban' => 'CZ'.fake()->numerify('######################'),
            'bic' => null,
            'is_default' => false,
        ];
    }
}
