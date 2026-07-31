<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Domain\Tenancy\CurrentOrganization;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\InvoiceNumberSeries;
use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use App\Models\Organization;
use App\Models\ReceivedInvoice;
use App\Models\ReceivedInvoiceAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Adversariální HTTP testy izolace organizací, autorizace a neměnnosti.
 *
 * Cross-tenant testy jsou regresní ochranou nálezu z bezpečnostního review:
 * SubstituteBindings kdysi běžel před middlewarem `org`, takže tenant scope
 * byl při route-model bindingu neaktivní a resolvovaly se i cizí záznamy.
 * Priorita middlewaru v bootstrap/app.php to řeší — tyto testy hlídají,
 * aby se regrese nevrátila.
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->userA = User::factory()->create();
        $this->userA->organizations()->attach($this->orgA, ['role' => 'member']);
    }

    /** Přihlásí userA a nastaví aktivní organizaci A do session. */
    private function actingInOrgA(): self
    {
        return $this->actingAs($this->userA)
            ->withSession(['current_organization_id' => $this->orgA->id]);
    }

    // ---------- AREA 1: guest / auth (OK — mají projít) ----------

    public function test_guest_is_redirected_to_login_from_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_user_cannot_select_organization_they_are_not_member_of(): void
    {
        $this->actingAs($this->userA)
            ->post(route('organizations.choose', $this->orgB))
            ->assertForbidden(); // 403 z abort_unless
    }

    // ---------- AREA 2: izolace organizací (cross-tenant) ----------

    public function test_foreign_invoice_show_returns_not_found(): void
    {
        $invoiceB = IssuedInvoice::factory()->issued()->create(['organization_id' => $this->orgB->id]);

        $this->actingInOrgA()
            ->get(route('invoices.show', $invoiceB))
            ->assertNotFound();
    }

    public function test_foreign_invoice_pdf_returns_not_found(): void
    {
        $invoiceB = IssuedInvoice::factory()->issued()->create(['organization_id' => $this->orgB->id]);

        $this->actingInOrgA()
            ->get(route('invoices.pdf', $invoiceB))
            ->assertNotFound();
    }

    public function test_foreign_bank_account_cannot_be_updated(): void
    {
        $accountB = BankAccount::factory()->create([
            'organization_id' => $this->orgB->id,
            'name' => 'Účet B',
        ]);

        $this->actingInOrgA()->put(route('bank-accounts.update', $accountB), [
            'name' => 'HACKED',
            'account_number' => '123456789',
            'bank_code' => '0100',
        ])->assertNotFound();

        $fresh = BankAccount::withoutGlobalScope('organization')->find($accountB->id);
        $this->assertSame('Účet B', $fresh->name);
    }

    public function test_foreign_number_series_cannot_be_updated(): void
    {
        $seriesB = InvoiceNumberSeries::factory()->create([
            'organization_id' => $this->orgB->id,
            'next_number' => 5,
        ]);

        $this->actingInOrgA()->put(route('number-series.update', $seriesB), [
            'name' => 'Faktury', 'prefix' => 'FV', 'year' => 2026,
            'next_number' => 1, 'number_format' => '{PREFIX}{YEAR}{NUMBER:4}',
        ])->assertNotFound();

        $fresh = InvoiceNumberSeries::withoutGlobalScope('organization')->find($seriesB->id);
        $this->assertSame(5, $fresh->next_number);
    }

    public function test_foreign_attachment_cannot_be_deleted(): void
    {
        $receivedB = ReceivedInvoice::factory()->create(['organization_id' => $this->orgB->id]);
        $attachmentB = ReceivedInvoiceAttachment::factory()->create([
            'organization_id' => $this->orgB->id,
            'received_invoice_id' => $receivedB->id,
        ]);

        $this->actingInOrgA()
            ->delete(route('attachments.destroy', $attachmentB))
            ->assertNotFound();

        $this->assertNotNull(
            ReceivedInvoiceAttachment::withoutGlobalScope('organization')->find($attachmentB->id)
        );
    }

    public function test_foreign_attachment_cannot_be_downloaded(): void
    {
        $receivedB = ReceivedInvoice::factory()->create(['organization_id' => $this->orgB->id]);
        $attachmentB = ReceivedInvoiceAttachment::factory()->create([
            'organization_id' => $this->orgB->id,
            'received_invoice_id' => $receivedB->id,
        ]);

        $this->actingInOrgA()
            ->get(route('attachments.download', $attachmentB))
            ->assertNotFound();
    }

    public function test_foreign_contact_and_project_are_not_reachable(): void
    {
        $contactB = Contact::factory()->create(['organization_id' => $this->orgB->id]);
        $projectB = \App\Models\Project::factory()->create(['organization_id' => $this->orgB->id]);

        $this->actingInOrgA()->get(route('contacts.edit', $contactB))->assertNotFound();
        $this->actingInOrgA()->delete(route('contacts.destroy', $contactB))->assertNotFound();
        $this->actingInOrgA()->get(route('projects.edit', $projectB))->assertNotFound();
    }

    public function test_foreign_received_invoice_cannot_be_transitioned(): void
    {
        $receivedB = ReceivedInvoice::factory()->create(['organization_id' => $this->orgB->id]);

        $this->actingInOrgA()->post(route('received.approve', $receivedB))->assertNotFound();
        $this->actingInOrgA()->post(route('received.mark-paid', $receivedB))->assertNotFound();
    }

    public function test_foreign_invoice_cannot_be_issued_or_paid(): void
    {
        $draftB = IssuedInvoice::factory()->draft()->create(['organization_id' => $this->orgB->id]);
        $issuedB = IssuedInvoice::factory()->issued()->create(['organization_id' => $this->orgB->id]);

        $this->actingInOrgA()->post(route('invoices.issue', $draftB))->assertNotFound();
        $this->actingInOrgA()->post(route('invoices.mark-paid', $issuedB))->assertNotFound();
        $this->actingInOrgA()->post(route('invoices.cancel', $issuedB))->assertNotFound();
    }

    // ---------- AREA 3: uploady ----------

    public function test_attachment_upload_rejects_disallowed_type_and_oversized_file(): void
    {
        Storage::fake('local');
        app(CurrentOrganization::class)->set($this->orgA);
        $received = ReceivedInvoice::factory()->create(['organization_id' => $this->orgA->id]);

        $this->actingInOrgA()->post(route('attachments.store', $received), [
            'attachment' => UploadedFile::fake()->create('exploit.php', 10, 'application/x-php'),
        ])->assertSessionHasErrors('attachment');

        $this->actingInOrgA()->post(route('attachments.store', $received), [
            'attachment' => UploadedFile::fake()->create('velka.pdf', 10241, 'application/pdf'),
        ])->assertSessionHasErrors('attachment');

        $this->assertSame(0, $received->attachments()->count());
    }

    public function test_attachment_upload_stores_file_on_private_disk_under_hashed_name(): void
    {
        Storage::fake('local');
        app(CurrentOrganization::class)->set($this->orgA);
        $received = ReceivedInvoice::factory()->create(['organization_id' => $this->orgA->id]);

        $this->actingInOrgA()->post(route('attachments.store', $received), [
            'attachment' => UploadedFile::fake()->create('Faktura 2026.pdf', 20, 'application/pdf'),
        ])->assertRedirect(route('received.show', $received));

        $attachment = $received->attachments()->firstOrFail();

        $this->assertSame('Faktura 2026.pdf', $attachment->original_filename);
        $this->assertStringNotContainsString('Faktura', $attachment->stored_path);
        Storage::disk('local')->assertExists($attachment->stored_path);

        $this->actingInOrgA()
            ->get(route('attachments.download', $attachment))
            ->assertOk()
            ->assertDownload('Faktura 2026.pdf');
    }

    public function test_logo_upload_rejects_non_image(): void
    {
        Storage::fake('local');

        $this->actingInOrgA()->post(route('settings.logo'), [
            'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('logo');

        $this->assertNull($this->orgA->fresh()->logo_path);
    }

    // ---------- AREA 5: neměnnost vystavené faktury (OK — mají projít) ----------

    public function test_model_blocks_protected_attribute_change_after_issue(): void
    {
        app(CurrentOrganization::class)->set($this->orgA);

        $invoice = IssuedInvoice::factory()->issued()->create(['organization_id' => $this->orgA->id]);

        $this->expectException(ImmutableInvoiceViolation::class);

        $invoice->update(['invoice_number' => 'HACKED-0001']);
    }

    public function test_http_update_of_issued_invoice_is_rejected_by_controller_guard(): void
    {
        app(CurrentOrganization::class)->set($this->orgA);
        $invoice = IssuedInvoice::factory()->issued()->create(['organization_id' => $this->orgA->id]);
        $contact = Contact::factory()->create(['organization_id' => $this->orgA->id]);
        $series = InvoiceNumberSeries::factory()->create(['organization_id' => $this->orgA->id]);
        $originalNumber = $invoice->invoice_number;
        $originalIssueDate = $invoice->issue_date->toDateString();

        $this->actingInOrgA()->put(route('invoices.update', $invoice), [
            'contact_id' => $contact->id,
            'number_series_id' => $series->id,
            'issue_date' => '2026-01-01',
            'due_date' => '2026-01-15',
            'items' => [[
                'description' => 'X', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '1',
            ]],
        ])->assertRedirect(route('invoices.show', $invoice));

        $fresh = IssuedInvoice::withoutGlobalScope('organization')->find($invoice->id);
        $this->assertSame($originalNumber, $fresh->invoice_number);
        $this->assertSame($originalIssueDate, $fresh->issue_date->toDateString());
    }

    // ---------- AREA 4: souběh číslování / manipulace next_number ----------

    /**
     * Snížení next_number pod již použité číslo vede při vystavení ke kolizi
     * s unikátním indexem. Uživatel dostane srozumitelnou hlášku, koncept
     * zůstane konceptem a duplicitní číslo nevznikne.
     */
    public function test_lowering_next_number_is_reported_instead_of_crashing(): void
    {
        $series = InvoiceNumberSeries::factory()->create([
            'organization_id' => $this->orgA->id,
            'prefix' => 'FV', 'year' => 2026, 'next_number' => 1,
            'number_format' => '{PREFIX}{YEAR}{NUMBER:4}',
        ]);

        IssuedInvoice::factory()->issued()->create([
            'organization_id' => $this->orgA->id,
            'invoice_number' => 'FV20260001',
        ]);

        $contact = Contact::factory()->create(['organization_id' => $this->orgA->id]);
        $draft = IssuedInvoice::factory()->draft()->create([
            'organization_id' => $this->orgA->id,
            'contact_id' => $contact->id,
            'number_series_id' => $series->id,
            'bank_account_id' => null,
        ]);
        IssuedInvoiceItem::factory()->create([
            'organization_id' => $this->orgA->id,
            'issued_invoice_id' => $draft->id,
        ]);

        // Org A nemá bankovní účet => účet není povinný.
        $this->actingInOrgA()->post(route('invoices.issue', $draft))
            ->assertRedirect(route('invoices.show', $draft))
            ->assertSessionHas('error');

        // Invariant drží: koncept zůstal konceptem, duplicitní číslo NEvzniklo.
        $this->assertSame('draft', IssuedInvoice::withoutGlobalScope('organization')
            ->find($draft->id)->status->value);
        $this->assertSame(1, IssuedInvoice::withoutGlobalScope('organization')
            ->where('organization_id', $this->orgA->id)
            ->where('invoice_number', 'FV20260001')->count());
    }
}
