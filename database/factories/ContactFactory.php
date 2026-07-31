<?php

namespace Database\Factories;

use App\Enums\ContactType;
use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    public function definition(): array
    {
        $ico = fake()->numerify('########');

        return [
            'organization_id' => Organization::factory(),
            'type' => ContactType::Customer,
            'name' => fake()->company(),
            'ico' => $ico,
            'dic' => 'CZ'.$ico,
            'street' => fake()->streetAddress(),
            'city' => fake()->city(),
            'zip' => fake()->postcode(),
            'country' => 'CZ',
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'note' => null,
        ];
    }

    public function customer(): static
    {
        return $this->state(['type' => ContactType::Customer]);
    }

    public function supplier(): static
    {
        return $this->state(['type' => ContactType::Supplier]);
    }
}
