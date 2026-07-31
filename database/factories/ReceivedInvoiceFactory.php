<?php

namespace Database\Factories;

use App\Enums\ContactType;
use App\Enums\ReceivedInvoiceStatus;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\ReceivedInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceivedInvoice>
 */
class ReceivedInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'contact_id' => fn (array $attributes) => Contact::factory()->create([
                'organization_id' => $attributes['organization_id'],
                'type' => ContactType::Supplier,
            ])->id,
            'project_id' => null,
            'supplier_invoice_number' => fake()->numerify('2026######'),
            'variable_symbol' => fake()->numerify('2026######'),
            'issue_date' => today()->subDays(3),
            'received_date' => today(),
            'due_date' => today()->addDays(14),
            'currency' => 'CZK',
            'total_minor' => 60500,
            'vat_minor' => 10500,
            'status' => ReceivedInvoiceStatus::Received,
            'note' => null,
            'paid_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => ReceivedInvoiceStatus::Approved]);
    }

    public function paid(): static
    {
        return $this->state([
            'status' => ReceivedInvoiceStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => ReceivedInvoiceStatus::Rejected]);
    }

    /**
     * Jen posune splatnost do minulosti — kombinuje se s approved() apod.
     */
    public function overdue(): static
    {
        return $this->state([
            'received_date' => today()->subDays(30),
            'due_date' => today()->subDays(10),
        ]);
    }
}
