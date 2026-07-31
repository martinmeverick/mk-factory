<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationSettings>
 */
class OrganizationSettingsFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'vat_payer' => true,
            'default_due_days' => 14,
            'default_bank_account_id' => null,
            'default_number_series_id' => null,
            'invoice_footer_text' => null,
            'invoice_default_note' => null,
        ];
    }
}
