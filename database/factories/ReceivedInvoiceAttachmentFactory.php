<?php

namespace Database\Factories;

use App\Models\ReceivedInvoice;
use App\Models\ReceivedInvoiceAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReceivedInvoiceAttachment>
 */
class ReceivedInvoiceAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'received_invoice_id' => ReceivedInvoice::factory(),
            'organization_id' => fn (array $attributes) => ReceivedInvoice::query()
                ->withoutGlobalScope('organization')
                ->findOrFail($attributes['received_invoice_id'])
                ->organization_id,
            'original_filename' => 'faktura.pdf',
            'stored_path' => 'received-invoices/'.Str::random(40).'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(10_000, 2_000_000),
        ];
    }
}
