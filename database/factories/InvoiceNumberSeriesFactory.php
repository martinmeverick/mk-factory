<?php

namespace Database\Factories;

use App\Models\InvoiceNumberSeries;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceNumberSeries>
 */
class InvoiceNumberSeriesFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Faktury',
            'prefix' => 'FV',
            'year' => 2026,
            'next_number' => 1,
            'number_format' => '{PREFIX}{YEAR}{NUMBER:4}',
        ];
    }
}
