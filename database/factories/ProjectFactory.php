<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->catchPhrase(),
            'code' => strtoupper(fake()->unique()->bothify('PRJ-###')),
            'contact_id' => null,
            'status' => ProjectStatus::Active,
            'external_id' => null,
            'note' => null,
        ];
    }
}
