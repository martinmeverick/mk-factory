<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => null,
            'subject_type' => null,
            'subject_id' => null,
            'action' => 'invoice.issued',
            'changes' => null,
        ];
    }
}
