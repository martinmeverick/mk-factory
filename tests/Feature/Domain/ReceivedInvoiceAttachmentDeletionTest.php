<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Invoicing\AttachmentNotFound;
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
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * CLOSURE REVIEW, nález 1: mazání JEDNÉ přílohy obcházelo zamčený
 * lifecycle. Controller volal `$attachment->delete()` a o finalitě
 * rozhodoval guard na modelu — ten sice četl stav z DATABÁZE, ale BEZ
 * zámku rodiče, takže mezi jeho čtením a samotným DELETE se vešel cizí
 * `markPaid()`.
 *
 * Sekvenční část kontraktu je tady; skutečný souběh (dvě procesy, zámek
 * řádku) ověřuje `Tests\Concurrency\ReceivedInvoiceAttachmentConcurrencyTest`
 * nad MariaDB — na SQLite je `SELECT … FOR UPDATE` no-op.
 */
class ReceivedInvoiceAttachmentDeletionTest extends TestCase
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

    private function invoiceWithAttachment(): array
    {
        $invoice = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);

        $attachment = $this->lifecycle->attach(
            $invoice,
            UploadedFile::fake()->create('doklad.pdf', 12, 'application/pdf'),
        );

        return [$invoice, $attachment];
    }

    private function assertAttachmentSurvived(ReceivedInvoiceAttachment $attachment): void
    {
        $this->assertSame(
            1,
            ReceivedInvoiceAttachment::query()->withoutGlobalScope('organization')->whereKey($attachment->id)->count(),
            'Odmítnuté mazání nesmí záznam přílohy odstranit.',
        );

        $this->assertTrue(
            Storage::disk('local')->exists($attachment->stored_path),
            'Odmítnuté mazání nesmí sáhnout na soubor.',
        );
    }

    // ---------- finalita rodiče ----------

    public function test_attachment_of_a_paid_invoice_cannot_be_deleted(): void
    {
        [$invoice, $attachment] = $this->invoiceWithAttachment();

        $this->lifecycle->markPaid($invoice, CarbonImmutable::parse('2026-08-05'));

        try {
            $this->lifecycle->deleteAttachment($attachment);
            $this->fail('Příloha uhrazené faktury nesmí jít smazat.');
        } catch (InvalidStateTransition $e) {
            $this->assertStringContainsString('uhrazené', $e->getMessage());
        }

        $this->assertAttachmentSurvived($attachment);
    }

    public function test_attachment_of_a_rejected_invoice_cannot_be_deleted(): void
    {
        [$invoice, $attachment] = $this->invoiceWithAttachment();

        $this->lifecycle->reject($invoice);

        try {
            $this->lifecycle->deleteAttachment($attachment);
            $this->fail('Příloha zamítnuté faktury nesmí jít smazat.');
        } catch (InvalidStateTransition $e) {
            $this->assertStringContainsString('zamítnuté', $e->getMessage());
        }

        $this->assertAttachmentSurvived($attachment);
    }

    /**
     * Instance přílohy načtená PŘED finalizací dokladu. O výsledku
     * rozhoduje zamčený rodič v DB, ne stav, který instance pamatuje.
     */
    public function test_a_stale_attachment_instance_cannot_be_deleted_after_finalization(): void
    {
        [$invoice, $attachment] = $this->invoiceWithAttachment();

        $stale = ReceivedInvoiceAttachment::query()->findOrFail($attachment->id);

        $this->lifecycle->markPaid($invoice, CarbonImmutable::parse('2026-08-05'));

        try {
            $this->lifecycle->deleteAttachment($stale);
            $this->fail('Zastaralá instance nesmí smazat přílohu uhrazené faktury.');
        } catch (InvalidStateTransition) {
            // očekáváno
        }

        $this->assertAttachmentSurvived($attachment);
    }

    // ---------- tenant izolace ----------

    public function test_another_organization_cannot_delete_the_attachment(): void
    {
        [, $attachment] = $this->invoiceWithAttachment();

        app(CurrentOrganization::class)->set(Organization::factory()->create());

        try {
            $this->lifecycle->deleteAttachment($attachment);
            $this->fail('Cizí organizace nesmí smazat přílohu.');
        } catch (InvoiceNotFound) {
            // očekáváno
        }

        $this->assertAttachmentSurvived($attachment);
    }

    /**
     * Podvržené parent ID v paměti nesmí guard přesměrovat na jiný,
     * nefinální doklad — rozhoduje ULOŽENÁ vazba.
     */
    public function test_a_forged_parent_id_does_not_bypass_the_guard(): void
    {
        [$invoice, $attachment] = $this->invoiceWithAttachment();

        $open = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);

        $this->lifecycle->markPaid($invoice, CarbonImmutable::parse('2026-08-05'));

        // Jen v paměti; uložená vazba stále ukazuje na uhrazenou fakturu.
        $attachment->received_invoice_id = $open->id;

        try {
            $this->lifecycle->deleteAttachment($attachment);
            $this->fail('Podvržené parent ID nesmí guard obejít.');
        } catch (InvalidStateTransition) {
            // očekáváno
        }

        $this->assertAttachmentSurvived($attachment);
    }

    /**
     * Totéž pro tenant identitu: přepsané organization_id v paměti nesmí
     * přílohu „přestěhovat“ pod organizaci volajícího.
     */
    public function test_a_forged_organization_id_does_not_bypass_the_tenant_check(): void
    {
        [, $attachment] = $this->invoiceWithAttachment();

        $intruder = Organization::factory()->create();
        app(CurrentOrganization::class)->set($intruder);

        $attachment->organization_id = $intruder->id;

        try {
            $this->lifecycle->deleteAttachment($attachment);
            $this->fail('Podvržené organization_id nesmí projít.');
        } catch (InvoiceNotFound) {
            // očekáváno
        }

        $this->assertAttachmentSurvived($attachment);
    }

    public function test_an_already_deleted_attachment_is_reported_as_missing(): void
    {
        [, $attachment] = $this->invoiceWithAttachment();

        $second = ReceivedInvoiceAttachment::query()->findOrFail($attachment->id);

        $this->assertTrue($this->lifecycle->deleteAttachment($attachment));

        try {
            $this->lifecycle->deleteAttachment($second);
            $this->fail('Druhé mazání téže přílohy musí skončit AttachmentNotFound.');
        } catch (AttachmentNotFound) {
            // očekáváno
        }
    }

    // ---------- povolená cesta a pořadí DB → filesystem ----------

    public function test_deleting_an_attachment_of_an_open_invoice_removes_row_file_and_writes_audit(): void
    {
        [, $attachment] = $this->invoiceWithAttachment();
        $path = $attachment->stored_path;

        $this->assertTrue($this->lifecycle->deleteAttachment($attachment));

        $this->assertSame(0, ReceivedInvoiceAttachment::query()->withoutGlobalScope('organization')->count());
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Na disku nesmí zůstat osiřelý soubor.');
        $this->assertSame(1, AuditLog::query()->where('action', 'invoice.attachment_removed')->count());
    }

    /**
     * Selhání filesystemu PO commitu se nesmí vracet do DB. Přijatelný
     * failure window je osiřelý soubor bez odkazu z DB; opačný směr
     * (živý záznam bez souboru) přijatelný není.
     */
    public function test_a_filesystem_failure_after_commit_keeps_the_row_deleted_and_is_reported(): void
    {
        [, $attachment] = $this->invoiceWithAttachment();

        // Soubor zmizí „zpod rukou“ jinak než přes disk — smazání pak
        // vrátí neúspěch, ale DB záznam se vracet nesmí.
        Storage::shouldReceive('disk')
            ->with('local')
            ->andReturn($disk = Mockery::mock(Filesystem::class));
        $disk->shouldReceive('delete')->once()->andReturn(false);

        $this->assertFalse($this->lifecycle->deleteAttachment($attachment));

        $this->assertSame(
            0,
            ReceivedInvoiceAttachment::query()->withoutGlobalScope('organization')->count(),
            'DB záznam se po neúspěšném úklidu souboru nesmí vrátit.',
        );
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

    public function test_http_delete_of_a_paid_invoice_attachment_returns_a_controlled_response(): void
    {
        [$invoice, $attachment] = $this->invoiceWithAttachment();

        $this->lifecycle->markPaid($invoice, CarbonImmutable::parse('2026-08-05'));

        $response = $this->actingAsMember()->delete(route('attachments.destroy', $attachment));

        $response->assertRedirect(route('received.show', $invoice));
        $response->assertSessionHas('error');

        $this->assertAttachmentSurvived($attachment);
    }

    public function test_http_delete_of_an_open_invoice_attachment_still_works(): void
    {
        [$invoice, $attachment] = $this->invoiceWithAttachment();
        $path = $attachment->stored_path;

        $response = $this->actingAsMember()->delete(route('attachments.destroy', $attachment));

        $response->assertRedirect(route('received.show', $invoice));
        $response->assertSessionHas('status');

        $this->assertSame(0, ReceivedInvoiceAttachment::query()->withoutGlobalScope('organization')->count());
        $this->assertFalse(Storage::disk('local')->exists($path));
    }
}
