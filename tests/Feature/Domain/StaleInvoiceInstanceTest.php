<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Domain\Invoicing\InvalidStateTransition;
use App\Domain\Invoicing\InvoiceNotFound;
use App\Domain\Invoicing\IssuedInvoiceLifecycle;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\IssuedInvoiceStatus;
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
 * NÁLEZ 1 a 2: zastaralá PHP instance nesmí změnit ani smazat fakturu,
 * která byla mezitím vystavena.
 *
 * Dřívější guard porovnával stav načtené instance (`getOriginal('status')`),
 * takže stará draft instance ho prošla. Guard teď čte stav z DATABÁZE
 * a mutace jedou nad zamčeným řádkem.
 *
 * Tyto testy nejsou o skutečném souběhu (ten je v tests/Concurrency nad
 * MariaDB), ale o zastaralé instanci — ta jde reprodukovat i na SQLite.
 */
class StaleInvoiceInstanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private IssuedInvoiceLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($this->organization);

        OrganizationSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'vat_payer' => false,
        ]);

        BankAccount::factory()->create([
            'organization_id' => $this->organization->id,
            'iban' => 'CZ1801000000000123456789',
        ]);

        $this->lifecycle = app(IssuedInvoiceLifecycle::class);
    }

    private function draft(): IssuedInvoice
    {
        $invoice = IssuedInvoice::factory()->draft()->create([
            'organization_id' => $this->organization->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $this->organization->id])->id,
            'number_series_id' => InvoiceNumberSeries::factory()->create([
                'organization_id' => $this->organization->id,
            ])->id,
            'bank_account_id' => BankAccount::query()->firstOrFail()->id,
            'due_date' => '2026-08-15',
        ]);

        IssuedInvoiceItem::factory()->create([
            'organization_id' => $this->organization->id,
            'issued_invoice_id' => $invoice->id,
            'unit_price_minor' => 10000,
            'quantity' => '1',
            'vat_rate' => null,
        ]);

        return $invoice;
    }

    /**
     * Dvě instance téhož konceptu; první ho vystaví, druhá je zastaralá.
     *
     * @return array{0: IssuedInvoice, 1: IssuedInvoice}
     */
    private function issueViaFirstInstance(): array
    {
        $invoice = $this->draft();

        $stale = IssuedInvoice::query()->findOrFail($invoice->id);   // druhá instance, stav draft
        $fresh = IssuedInvoice::query()->findOrFail($invoice->id);

        $this->lifecycle->issue($fresh, CarbonImmutable::parse('2026-08-01'));

        $this->assertSame(IssuedInvoiceStatus::Draft, $stale->status, 'Instance musí být opravdu zastaralá.');

        return [$stale, $fresh];
    }

    public function test_stale_instance_cannot_change_due_date_after_issue(): void
    {
        [$stale] = $this->issueViaFirstInstance();

        $original = IssuedInvoice::query()->findOrFail($stale->id)->due_date->toDateString();

        try {
            $stale->update(['due_date' => '2027-01-01']);
            $this->fail('Zastaralá instance změnila datum splatnosti vystavené faktury.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        $this->assertSame($original, IssuedInvoice::query()->findOrFail($stale->id)->due_date->toDateString());
    }

    public function test_stale_instance_cannot_change_snapshots_or_number(): void
    {
        [$stale] = $this->issueViaFirstInstance();

        $protected = [
            'invoice_number' => 'PODVRZENE-0001',
            'variable_symbol' => '9999999999',
            'total_minor' => 1,
            'supplier_snapshot' => ['name' => 'Cizí dodavatel'],
            'customer_snapshot' => ['name' => 'Cizí odběratel'],
            'bank_account_snapshot' => ['iban' => 'CZ0000000000000000000000'],
            'contact_id' => 12345,
            'footer_text' => 'podvrh',
            'note' => 'podvrh',
        ];

        foreach ($protected as $attribute => $value) {
            $before = IssuedInvoice::query()->findOrFail($stale->id)->getAttribute($attribute);

            try {
                $stale->update([$attribute => $value]);
                $this->fail("Zastaralá instance změnila chráněný atribut {$attribute}.");
            } catch (ImmutableInvoiceViolation) {
                // očekáváno
            }

            $this->assertEquals(
                $before,
                IssuedInvoice::query()->findOrFail($stale->id)->getAttribute($attribute),
                "Atribut {$attribute} se změnil v databázi.",
            );
        }
    }

    public function test_stale_instance_cannot_delete_an_issued_invoice(): void
    {
        [$stale] = $this->issueViaFirstInstance();

        try {
            $stale->delete();
            $this->fail('Zastaralá instance smazala vystavenou fakturu.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        $this->assertDatabaseHas('issued_invoices', ['id' => $stale->id]);
    }

    public function test_lifecycle_delete_of_a_stale_draft_instance_is_refused_after_issue(): void
    {
        [$stale] = $this->issueViaFirstInstance();

        $this->expectException(InvalidStateTransition::class);

        $this->lifecycle->deleteDraft($stale);
    }

    public function test_lifecycle_update_of_a_stale_draft_instance_is_refused_after_issue(): void
    {
        [$stale] = $this->issueViaFirstInstance();

        try {
            $this->lifecycle->updateDraft($stale, ['note' => 'podvrh'], []);
            $this->fail('Zastaralá instance přepsala vystavenou fakturu.');
        } catch (InvalidStateTransition) {
            // očekáváno
        }

        $this->assertSame(
            1,
            IssuedInvoiceItem::query()->where('issued_invoice_id', $stale->id)->count(),
            'Položky vystavené faktury zůstávají.',
        );
    }

    public function test_stale_instance_cannot_touch_items_of_an_issued_invoice(): void
    {
        [$stale] = $this->issueViaFirstInstance();

        $item = IssuedInvoiceItem::query()->where('issued_invoice_id', $stale->id)->firstOrFail();

        $this->expectException(ImmutableInvoiceViolation::class);

        $item->update(['description' => 'podvrh']);
    }

    /**
     * NÁLEZ 1: cizí organization_id nesmí jít využít při zamčeném načtení.
     */
    public function test_foreign_organization_context_cannot_reach_the_invoice(): void
    {
        $invoice = $this->draft();

        $otherOrganization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($otherOrganization);

        $this->expectException(InvoiceNotFound::class);

        $this->lifecycle->issue($invoice, CarbonImmutable::parse('2026-08-01'));
    }

    public function test_foreign_organization_context_cannot_delete_the_invoice(): void
    {
        $invoice = $this->draft();

        app(CurrentOrganization::class)->set(Organization::factory()->create());

        try {
            $this->lifecycle->deleteDraft($invoice);
            $this->fail('Cizí organizace smazala fakturu.');
        } catch (InvoiceNotFound) {
            // očekáváno
        }

        $this->assertDatabaseHas('issued_invoices', ['id' => $invoice->id]);
    }

    /**
     * NÁLEZ 2: obecný veřejný escape hatch už neexistuje.
     */
    public function test_general_lifecycle_escape_hatch_is_gone(): void
    {
        $this->assertFalse(
            method_exists(IssuedInvoice::class, 'allowLifecycleTransition'),
            'allowLifecycleTransition() nesmí existovat — byl to obecný přepínač ochrany.',
        );
    }

    /**
     * NÁLEZ 2 + RE-REVIEW: interní zápis lifecycle služby neumí zapsat
     * atribut mimo whitelist — ani tenant identitu. Test se do privátního
     * scope váže stejně jako lifecycle (Closure::bind), protože veřejná
     * zápisová metoda už neexistuje.
     */
    public function test_internal_lifecycle_write_rejects_attributes_outside_its_whitelist(): void
    {
        $invoice = $this->draft();

        $this->expectException(ImmutableInvoiceViolation::class);

        \Closure::bind(function (): void {
            /** @var IssuedInvoice $this */
            $this->persistLifecycleState(['organization_id' => 999]);
        }, $invoice, IssuedInvoice::class)();
    }

    /**
     * NÁLEZ 2: ani přes veřejné lifecycle metody nelze u vystavené faktury
     * změnit chráněný atribut.
     */
    public function test_no_public_lifecycle_path_can_change_a_protected_attribute(): void
    {
        [, $fresh] = $this->issueViaFirstInstance();

        $before = IssuedInvoice::query()->findOrFail($fresh->id);

        // Platba i storno jsou legitimní přechody — nesmí ale sáhnout na
        // číslo faktury, snapshoty ani částky dokladu.
        $this->lifecycle->registerPayment($fresh, 4000, CarbonImmutable::parse('2026-08-05'));

        $after = IssuedInvoice::query()->findOrFail($fresh->id);

        foreach (IssuedInvoice::PROTECTED_ATTRIBUTES as $attribute) {
            $this->assertEquals(
                $before->getAttribute($attribute),
                $after->getAttribute($attribute),
                "Lifecycle změnil chráněný atribut {$attribute}.",
            );
        }
    }
}
