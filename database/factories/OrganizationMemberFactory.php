<?php

namespace Database\Factories;

use App\Enums\MemberRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationMember>
 */
class OrganizationMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => MemberRole::Member,
        ];
    }

    public function owner(): static
    {
        return $this->state(['role' => MemberRole::Owner]);
    }
}
