<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Enums\IssuedInvoiceStatus;
use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_due_date_after_issue_throws(): void
    {
        $invoice = IssuedInvoice::factory()->issued()->create();

        $this->expectException(ImmutableInvoiceViolation::class);

        $invoice->update(['due_date' => today()->addDays(60)]);
    }

    public function test_changing_invoice_number_after_issue_throws(): void
    {
        $invoice = IssuedInvoice::factory()->issued()->create();

        $this->expectException(ImmutableInvoiceViolation::class);

        $invoice->update(['invoice_number' => 'FV9999']);
    }

    public function test_changing_totals_after_issue_throws(): void
    {
        $invoice = IssuedInvoice::factory()->issued()->create();

        $this->expectException(ImmutableInvoiceViolation::class);

        $invoice->update(['total_minor' => 1]);
    }

    public function test_protected_change_is_not_persisted(): void
    {
        $invoice = IssuedInvoice::factory()->issued()->create();
        $originalNumber = $invoice->invoice_number;

        try {
            $invoice->update(['invoice_number' => 'FV9999']);
            $this->fail('Očekávána ImmutableInvoiceViolation.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        $this->assertSame($originalNumber, $invoice->fresh()->invoice_number);
    }

    public function test_internal_note_and_project_can_change_after_issue(): void
    {
        $invoice = IssuedInvoice::factory()->issued()->create();
        $project = Project::factory()->create(['organization_id' => $invoice->organization_id]);

        $invoice->update([
            'internal_note' => 'Interní poznámka po vystavení.',
            'project_id' => $project->id,
        ]);

        $fresh = $invoice->fresh();
        $this->assertSame('Interní poznámka po vystavení.', $fresh->internal_note);
        $this->assertSame($project->id, $fresh->project_id);
    }

    public function test_draft_remains_fully_editable(): void
    {
        $invoice = IssuedInvoice::factory()->draft()->create();

        $invoice->update(['due_date' => today()->addDays(60), 'total_minor' => 5]);

        $this->assertSame(5, $invoice->fresh()->total_minor);
    }

    public function test_items_cannot_be_added_after_issue(): void
    {
        $invoice = IssuedInvoice::factory()->issued()->create();

        $this->expectException(ImmutableInvoiceViolation::class);

        IssuedInvoiceItem::factory()->create([
            'issued_invoice_id' => $invoice->id,
            'organization_id' => $invoice->organization_id,
        ]);
    }

    public function test_items_cannot_be_updated_after_issue(): void
    {
        $invoice = IssuedInvoice::factory()->draft()->create();
        $item = IssuedInvoiceItem::factory()->create([
            'issued_invoice_id' => $invoice->id,
            'organization_id' => $invoice->organization_id,
        ]);

        // Přechod draft → issued přímou změnou (původní stav draft => povoleno).
        $invoice->update(['status' => IssuedInvoiceStatus::Issued]);

        $this->expectException(ImmutableInvoiceViolation::class);

        $item->update(['description' => 'Změna po vystavení']);
    }

    public function test_items_cannot_be_deleted_after_issue(): void
    {
        $invoice = IssuedInvoice::factory()->draft()->create();
        $item = IssuedInvoiceItem::factory()->create([
            'issued_invoice_id' => $invoice->id,
            'organization_id' => $invoice->organization_id,
        ]);

        $invoice->update(['status' => IssuedInvoiceStatus::Issued]);

        $this->expectException(ImmutableInvoiceViolation::class);

        $item->delete();
    }

    public function test_items_of_draft_are_editable(): void
    {
        $invoice = IssuedInvoice::factory()->draft()->create();
        $item = IssuedInvoiceItem::factory()->create([
            'issued_invoice_id' => $invoice->id,
            'organization_id' => $invoice->organization_id,
        ]);

        $item->update(['description' => 'Upraveno']);
        $this->assertSame('Upraveno', $item->fresh()->description);

        $item->delete();
        $this->assertSame(0, $invoice->items()->count());
    }
}
