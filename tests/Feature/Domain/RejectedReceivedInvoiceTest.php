<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Domain\Invoicing\InvalidStateTransition;
use App\Domain\Invoicing\ReceivedInvoiceLifecycle;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ReceivedInvoiceStatus;
use App\Models\Organization;
use App\Models\ReceivedInvoice;
use App\Models\ReceivedInvoiceAttachment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * RE-REVIEW, nález 5: stav `paid` byl chráněný, `rejected` ne. Zamítnutou
 * fakturu šlo smazat a její přílohy měnit i mazat, protože finalita se
 * na několika místech testovala ručně jako `=== Paid`.
 *
 * Jediný zdroj pravdy je teď ReceivedInvoice::FINAL_STATUSES (paid,
 * rejected) a používá ho model, lifecycle, guardy příloh i controller.
 */
class RejectedReceivedInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private ReceivedInvoiceLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($this->organization);

        $this->lifecycle = app(ReceivedInvoiceLifecycle::class);
    }

    private function rejected(): ReceivedInvoice
    {
        $invoice = ReceivedInvoice::factory()->create([
            'organization_id' => $this->organization->id,
            'due_date' => '2026-08-15',
        ]);

        $this->lifecycle->reject($invoice);

        return $invoice->fresh();
    }

    public function test_final_statuses_cover_paid_and_rejected(): void
    {
        $this->assertSame(
            [ReceivedInvoiceStatus::Paid, ReceivedInvoiceStatus::Rejected],
            ReceivedInvoice::FINAL_STATUSES,
        );

        $this->assertTrue(ReceivedInvoice::isFinalStatus(ReceivedInvoiceStatus::Paid));
        $this->assertTrue(ReceivedInvoice::isFinalStatus(ReceivedInvoiceStatus::Rejected));
        $this->assertFalse(ReceivedInvoice::isFinalStatus(ReceivedInvoiceStatus::Received));
        $this->assertFalse(ReceivedInvoice::isFinalStatus(ReceivedInvoiceStatus::Approved));
        $this->assertFalse(ReceivedInvoice::isFinalStatus(null));
    }

    public function test_rejected_invoice_cannot_be_updated(): void
    {
        $invoice = $this->rejected();

        try {
            $this->lifecycle->updateDetails($invoice, ['due_date' => '2027-01-01', 'note' => 'podvrh']);
            $this->fail('Zamítnutou fakturu nesmí jít upravit.');
        } catch (InvalidStateTransition $e) {
            $this->assertStringContainsString('zamítnutou', mb_strtolower($e->getMessage()));
        }

        $fresh = ReceivedInvoice::query()->findOrFail($invoice->id);
        $this->assertSame('2026-08-15', $fresh->due_date->toDateString());
        $this->assertNull($fresh->note);
    }

    public function test_rejected_invoice_cannot_be_deleted(): void
    {
        $invoice = $this->rejected();

        try {
            $this->lifecycle->delete($invoice);
            $this->fail('Zamítnutou fakturu nesmí jít smazat.');
        } catch (InvalidStateTransition) {
            // očekáváno
        }

        $this->assertDatabaseHas('received_invoices', ['id' => $invoice->id]);
    }

    public function test_rejected_invoice_cannot_be_deleted_through_the_model_either(): void
    {
        $invoice = $this->rejected();

        $this->expectException(ImmutableInvoiceViolation::class);

        $invoice->delete();
    }

    /**
     * Stale instance načtená PŘED zamítnutím ji nesmí změnit ani smazat —
     * rozhoduje stav v databázi, ne v paměti.
     */
    public function test_stale_instance_loaded_before_rejection_can_neither_update_nor_delete(): void
    {
        $invoice = ReceivedInvoice::factory()->create([
            'organization_id' => $this->organization->id,
            'due_date' => '2026-08-15',
        ]);

        $stale = ReceivedInvoice::query()->findOrFail($invoice->id);
        $fresh = ReceivedInvoice::query()->findOrFail($invoice->id);

        $this->lifecycle->reject($fresh);

        $this->assertSame(ReceivedInvoiceStatus::Received, $stale->status, 'Instance musí být opravdu zastaralá.');

        try {
            $stale->update(['note' => 'podvrh']);
            $this->fail('Zastaralá instance změnila zamítnutou fakturu.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        try {
            $this->lifecycle->updateDetails($stale, ['note' => 'podvrh']);
            $this->fail('Lifecycle update zastaralé instance musí selhat.');
        } catch (InvalidStateTransition) {
            // očekáváno
        }

        try {
            $this->lifecycle->delete($stale);
            $this->fail('Lifecycle delete zastaralé instance musí selhat.');
        } catch (InvalidStateTransition) {
            // očekáváno
        }

        $this->assertDatabaseHas('received_invoices', ['id' => $invoice->id]);
        $this->assertNull(ReceivedInvoice::query()->findOrFail($invoice->id)->note);
    }

    public function test_attachment_cannot_be_added_to_a_rejected_invoice(): void
    {
        Storage::fake('local');

        $invoice = $this->rejected();

        try {
            $this->lifecycle->attach($invoice, UploadedFile::fake()->create('doklad.pdf', 10, 'application/pdf'));
            $this->fail('K zamítnuté faktuře nesmí jít přidat příloha.');
        } catch (InvalidStateTransition) {
            // očekáváno
        }

        $this->assertSame(0, ReceivedInvoiceAttachment::query()->count());
        $this->assertEmpty(Storage::disk('local')->allFiles(), 'Nesmí zůstat žádný soubor.');
    }

    public function test_attachments_of_a_rejected_invoice_cannot_be_changed_or_deleted(): void
    {
        Storage::fake('local');

        $invoice = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);
        $attachment = ReceivedInvoiceAttachment::factory()->create([
            'received_invoice_id' => $invoice->id,
            'organization_id' => $this->organization->id,
            'original_filename' => 'puvodni.pdf',
        ]);

        $this->lifecycle->reject($invoice);

        try {
            $attachment->update(['original_filename' => 'podvrh.pdf']);
            $this->fail('Přílohu zamítnuté faktury nesmí jít změnit.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        try {
            $attachment->delete();
            $this->fail('Přílohu zamítnuté faktury nesmí jít smazat.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        $this->assertSame(
            'puvodni.pdf',
            ReceivedInvoiceAttachment::query()->findOrFail($attachment->id)->original_filename,
        );
    }

    public function test_paid_protections_remain_intact(): void
    {
        $invoice = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);
        $this->lifecycle->markPaid($invoice, CarbonImmutable::parse('2026-08-05'));

        foreach ([
            fn () => $this->lifecycle->updateDetails($invoice, ['note' => 'podvrh']),
            fn () => $this->lifecycle->delete($invoice),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Uhrazená faktura musí zůstat chráněná.');
            } catch (InvalidStateTransition) {
                // očekáváno
            }
        }

        $this->assertDatabaseHas('received_invoices', ['id' => $invoice->id]);
    }

    public function test_non_final_invoices_remain_editable_and_deletable(): void
    {
        $editable = ReceivedInvoice::factory()->create([
            'organization_id' => $this->organization->id,
            'due_date' => '2026-08-15',
        ]);

        $this->lifecycle->updateDetails($editable, ['due_date' => '2026-09-01']);
        $this->assertSame('2026-09-01', ReceivedInvoice::query()->findOrFail($editable->id)->due_date->toDateString());

        $approved = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);
        $this->lifecycle->approve($approved);
        $this->lifecycle->updateDetails($approved, ['note' => 'schválená se upravit smí']);
        $this->assertSame('schválená se upravit smí', ReceivedInvoice::query()->findOrFail($approved->id)->note);

        $this->lifecycle->delete($approved);
        $this->assertDatabaseMissing('received_invoices', ['id' => $approved->id]);
    }

    public function test_the_view_hides_actions_for_a_final_invoice(): void
    {
        $rejected = $this->rejected();

        $this->assertTrue($rejected->isFinal());
        $this->assertFalse(
            ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id])->isFinal(),
        );
    }
}
