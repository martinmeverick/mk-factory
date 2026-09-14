<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\AuditTenantMismatch;
use App\Domain\Invoicing\IssuedInvoiceLifecycle;
use App\Domain\Tenancy\CurrentOrganization;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\InvoiceNumberSeries;
use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use App\Models\Organization;
use App\Models\OrganizationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NÁLEZ 8: audit odvozuje organizaci ze SUBJEKTU, ne z ambientního
 * tenant contextu.
 *
 * Před opravou vznikal audit s organization_id z CurrentOrganization —
 * v konzoli, frontě nebo testu bez contextu tedy s NULL, a při nastaveném
 * cizím contextu dokonce pod špatnou organizací.
 */
class AuditTenantTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationA = Organization::factory()->create(['name' => 'Organizace A']);
        $this->organizationB = Organization::factory()->create(['name' => 'Organizace B']);
    }

    private function invoiceOfA(): IssuedInvoice
    {
        $contact = Contact::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organizationA->id,
            'type' => 'customer',
            'name' => 'Odběratel A',
            'country' => 'CZ',
        ]);

        return IssuedInvoice::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organizationA->id,
            'contact_id' => $contact->id,
            'status' => 'draft',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-15',
            'currency' => 'CZK',
        ]);
    }

    public function test_audit_of_organization_a_under_context_b_is_refused(): void
    {
        $invoice = $this->invoiceOfA();

        app(CurrentOrganization::class)->set($this->organizationB);

        try {
            app(AuditLogger::class)->log('invoice.issued', $invoice);
            $this->fail('Audit vznikl pod cizí organizací.');
        } catch (AuditTenantMismatch $e) {
            $this->assertStringContainsString((string) $this->organizationA->id, $e->getMessage());
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_without_ambient_context_uses_the_subject_organization(): void
    {
        $invoice = $this->invoiceOfA();

        app(CurrentOrganization::class)->set(null);

        app(AuditLogger::class)->log('invoice.issued', $invoice);

        $log = AuditLog::withoutGlobalScope('organization')->firstOrFail();

        $this->assertSame($this->organizationA->id, (int) $log->organization_id);
    }

    public function test_subjectless_audit_without_explicit_organization_is_refused(): void
    {
        app(CurrentOrganization::class)->set($this->organizationA);

        $this->expectException(AuditTenantMismatch::class);

        app(AuditLogger::class)->log('system.maintenance');
    }

    public function test_subjectless_audit_with_explicit_organization_is_stored(): void
    {
        app(CurrentOrganization::class)->set(null);

        app(AuditLogger::class)->log('system.maintenance', null, [], $this->organizationA->id);

        $log = AuditLog::withoutGlobalScope('organization')->firstOrFail();

        $this->assertSame($this->organizationA->id, (int) $log->organization_id);
    }

    public function test_explicit_organization_conflicting_with_the_subject_is_refused(): void
    {
        $invoice = $this->invoiceOfA();

        app(CurrentOrganization::class)->set(null);

        $this->expectException(AuditTenantMismatch::class);

        app(AuditLogger::class)->log('invoice.issued', $invoice, [], $this->organizationB->id);
    }

    /**
     * Žádná lifecycle operace nesmí vytvořit audit s NULL organizací —
     * ani když běží bez tenant contextu (konzole, fronta).
     */
    public function test_no_lifecycle_operation_creates_an_audit_without_organization(): void
    {
        app(CurrentOrganization::class)->set($this->organizationA);

        OrganizationSettings::factory()->create([
            'organization_id' => $this->organizationA->id,
            'vat_payer' => false,
        ]);

        $invoice = IssuedInvoice::factory()->draft()->create([
            'organization_id' => $this->organizationA->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $this->organizationA->id])->id,
            'number_series_id' => InvoiceNumberSeries::factory()->create([
                'organization_id' => $this->organizationA->id,
            ])->id,
            'bank_account_id' => BankAccount::factory()->create([
                'organization_id' => $this->organizationA->id,
                'iban' => 'CZ1801000000000123456789',
            ])->id,
            'due_date' => '2026-08-15',
        ]);

        IssuedInvoiceItem::factory()->create([
            'organization_id' => $this->organizationA->id,
            'issued_invoice_id' => $invoice->id,
            'quantity' => '1',
            'unit_price_minor' => 10000,
            'vat_rate' => null,
        ]);

        $lifecycle = app(IssuedInvoiceLifecycle::class);

        // Simulace běhu bez tenant contextu (konzole / fronta).
        app(CurrentOrganization::class)->set(null);

        $lifecycle->issue($invoice, CarbonImmutable::parse('2026-08-01'));
        $lifecycle->registerPayment($invoice, 4000, CarbonImmutable::parse('2026-08-02'));
        $lifecycle->markPaid($invoice, CarbonImmutable::parse('2026-08-03'));

        $logs = AuditLog::withoutGlobalScope('organization')->get();

        $this->assertGreaterThanOrEqual(3, $logs->count());
        $this->assertSame(
            0,
            $logs->whereNull('organization_id')->count(),
            'Audit s NULL organizací nesmí vzniknout.',
        );
        $this->assertTrue(
            $logs->every(fn (AuditLog $log): bool => (int) $log->organization_id === $this->organizationA->id),
            'Všechny audity musí patřit organizaci subjektu.',
        );
    }
}
