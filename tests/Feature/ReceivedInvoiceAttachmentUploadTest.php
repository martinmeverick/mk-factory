<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Invoicing\InvalidStateTransition;
use App\Domain\Invoicing\InvoiceNotFound;
use App\Domain\Invoicing\ReceivedInvoiceLifecycle;
use App\Domain\Tenancy\CurrentOrganization;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\ReceivedInvoice;
use App\Models\ReceivedInvoiceAttachment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * RE-REVIEW, nález 7: upload ukládal soubor DŘÍV, než vznikl DB záznam.
 * Guard finální faktury insert odmítl až potom, takže soubor zůstal
 * osiřelý na disku a HTTP vrátilo neošetřenou 500.
 *
 * Pořadí je teď: transakce → tenant-scoped zamčení → kontrola stavu →
 * zápis souboru → DB záznam → audit → commit, plus kompenzační úklid
 * souboru při JAKÉKOLI chybě.
 */
class ReceivedInvoiceAttachmentUploadTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private ReceivedInvoiceLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->organization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($this->organization);

        $this->lifecycle = app(ReceivedInvoiceLifecycle::class);
    }

    private function file(string $name = 'doklad.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 12, 'application/pdf');
    }

    private function assertNoOrphanFiles(): void
    {
        $this->assertSame(
            [],
            Storage::disk('local')->allFiles(),
            'Na disku nesmí zůstat žádný osiřelý soubor.',
        );
    }

    public function test_upload_to_an_open_invoice_succeeds(): void
    {
        $invoice = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);

        $attachment = $this->lifecycle->attach($invoice, $this->file('Faktura 2026.pdf'));

        $this->assertInstanceOf(ReceivedInvoiceAttachment::class, $attachment);
        $this->assertSame('Faktura 2026.pdf', $attachment->original_filename);
        $this->assertSame((int) $this->organization->id, (int) $attachment->organization_id);
        $this->assertTrue(Storage::disk('local')->exists($attachment->stored_path));
        $this->assertStringStartsWith('attachments/org-'.$this->organization->id.'/', $attachment->stored_path);
        $this->assertSame(1, AuditLog::query()->where('action', 'invoice.attachment_added')->count());
    }

    public function test_stale_upload_to_a_paid_invoice_leaves_no_row_and_no_file(): void
    {
        $invoice = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);

        // Instance načtená před úhradou — přesně scénář z re-review.
        $stale = ReceivedInvoice::query()->findOrFail($invoice->id);
        $this->lifecycle->markPaid($invoice, CarbonImmutable::parse('2026-08-05'));

        try {
            $this->lifecycle->attach($stale, $this->file());
            $this->fail('Upload k uhrazené faktuře musí být odmítnut.');
        } catch (InvalidStateTransition $e) {
            $this->assertStringContainsString('přílohu', mb_strtolower($e->getMessage()));
        }

        $this->assertSame(0, ReceivedInvoiceAttachment::query()->count());
        $this->assertNoOrphanFiles();
    }

    public function test_upload_to_a_rejected_invoice_leaves_no_row_and_no_file(): void
    {
        $invoice = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);
        $this->lifecycle->reject($invoice);

        try {
            $this->lifecycle->attach($invoice, $this->file());
            $this->fail('Upload k zamítnuté faktuře musí být odmítnut.');
        } catch (InvalidStateTransition) {
            // očekáváno
        }

        $this->assertSame(0, ReceivedInvoiceAttachment::query()->count());
        $this->assertNoOrphanFiles();
    }

    /**
     * Soubor už na disku je, ale DB zápis (nebo audit) selže — kompenzace
     * ho musí uklidit, ne nechat ležet.
     */
    public function test_database_failure_after_storing_the_file_removes_the_file(): void
    {
        $invoice = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);

        ReceivedInvoiceAttachment::created(function (): void {
            throw new RuntimeException('Simulovaná chyba DB po zápisu souboru.');
        });

        try {
            $this->lifecycle->attach($invoice, $this->file());
            $this->fail('Chyba po zápisu souboru musí operaci odvalit.');
        } catch (RuntimeException) {
            // očekáváno
        }

        $this->assertSame(0, ReceivedInvoiceAttachment::query()->count(), 'DB musí být odvalená.');
        $this->assertNoOrphanFiles();
        $this->assertSame(0, AuditLog::query()->where('action', 'invoice.attachment_added')->count());
    }

    public function test_upload_from_another_organization_is_refused(): void
    {
        $invoice = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);

        app(CurrentOrganization::class)->set(Organization::factory()->create());

        try {
            $this->lifecycle->attach($invoice, $this->file());
            $this->fail('Cizí organizace nesmí přidat přílohu.');
        } catch (InvoiceNotFound) {
            // očekáváno
        }

        $this->assertSame(0, ReceivedInvoiceAttachment::query()->withoutGlobalScope('organization')->count());
        $this->assertNoOrphanFiles();
    }

    // ---------- HTTP průchod ----------

    private function actingAsMember(): self
    {
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $this->organization->id]);

        return $this;
    }

    public function test_http_upload_to_a_paid_invoice_returns_a_controlled_response(): void
    {
        $invoice = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);
        $this->lifecycle->markPaid($invoice, CarbonImmutable::parse('2026-08-05'));

        $response = $this->actingAsMember()->post(route('attachments.store', $invoice), [
            'attachment' => $this->file(),
        ]);

        // Kontrolovaná odpověď, ne neošetřená 500.
        $response->assertRedirect(route('received.show', $invoice));
        $response->assertSessionHas('error');

        $this->assertSame(0, ReceivedInvoiceAttachment::query()->count());
        $this->assertNoOrphanFiles();
    }

    public function test_http_upload_to_an_open_invoice_still_works(): void
    {
        $invoice = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);

        $response = $this->actingAsMember()->post(route('attachments.store', $invoice), [
            'attachment' => $this->file(),
        ]);

        $response->assertRedirect(route('received.show', $invoice));
        $response->assertSessionHas('status');

        $this->assertSame(1, ReceivedInvoiceAttachment::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }
}
