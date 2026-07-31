<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $ico = fake()->numerify('########');

        return [
            'name' => fake()->company(),
            'ico' => $ico,
            'dic' => 'CZ'.$ico,
            'street' => fake()->streetAddress(),
            'city' => fake()->city(),
            'zip' => fake()->postcode(),
            'country' => 'CZ',
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'website' => null,
            'logo_path' => null,
        ];
    }
}
