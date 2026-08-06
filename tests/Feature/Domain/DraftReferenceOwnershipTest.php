<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Invoicing\InvalidInvoiceReference;
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
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RE-REVIEW, nález 2: whitelist klíčů v updateDraft() nekontroloval, KAM
 * povolené reference ukazují. `contact_id`, `project_id`, `bank_account_id`
 * i `number_series_id` tedy šlo nastavit na záznam cizí organizace —
 * HTTP validace některé případy chytí, ale doménová služba si musí hlídat
 * vlastní kontrakt.
 *
 * Vlastnictví se ověřuje proti organization_id ZAMČENÉ faktury, ne proti
 * ambientnímu tenant contextu.
 */
class DraftReferenceOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $foreign;

    private IssuedInvoiceLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->foreign = Organization::factory()->create();

        app(CurrentOrganization::class)->set($this->organization);

        OrganizationSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'vat_payer' => false,
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
                // Unikátní prefix — test zakládá víc řad v jedné organizaci.
                'prefix' => 'F'.fake()->unique()->numerify('##'),
            ])->id,
            'bank_account_id' => BankAccount::factory()->create([
                'organization_id' => $this->organization->id,
                'iban' => 'CZ1801000000000123456789',
            ])->id,
            'due_date' => '2026-08-15',
            'note' => 'původní poznámka',
        ]);

        IssuedInvoiceItem::factory()->create([
            'organization_id' => $this->organization->id,
            'issued_invoice_id' => $invoice->id,
            'description' => 'Původní položka',
            'unit_price_minor' => 10000,
            'quantity' => '1',
            'vat_rate' => null,
        ]);

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $description = 'Nová položka'): array
    {
        return [
            'description' => $description,
            'quantity' => '2',
            'unit' => 'ks',
            'unit_price_minor' => 5000,
            'vat_rate' => null,
            'line_subtotal_minor' => 0,
            'line_vat_minor' => 0,
            'line_total_minor' => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function foreignReferences(): array
    {
        return [
            'contact_id' => Contact::factory()->create(['organization_id' => $this->foreign->id])->id,
            'project_id' => Project::factory()->create(['organization_id' => $this->foreign->id])->id,
            'bank_account_id' => BankAccount::factory()->create([
                'organization_id' => $this->foreign->id,
                'iban' => 'CZ6508000000192000145399',
            ])->id,
            'number_series_id' => InvoiceNumberSeries::factory()->create([
                'organization_id' => $this->foreign->id,
                'prefix' => 'C'.fake()->unique()->numerify('##'),
            ])->id,
        ];
    }

    /**
     * Statická pojistka: každý cizí klíč, který whitelist hlavičky pouští,
     * musí mít tenant validaci. Nové povolené `*_id` bez záznamu
     * v DRAFT_REFERENCES tenhle test shodí.
     */
    public function test_every_whitelisted_foreign_key_is_tenant_validated(): void
    {
        $reflection = new \ReflectionClass(IssuedInvoiceLifecycle::class);

        $foreignKeys = array_values(array_filter(
            $reflection->getConstant('DRAFT_HEADER_ATTRIBUTES'),
            fn (string $attribute): bool => str_ends_with($attribute, '_id'),
        ));

        $this->assertSame(
            [],
            array_diff($foreignKeys, array_keys($reflection->getConstant('DRAFT_REFERENCES'))),
            'Každý povolený cizí klíč hlavičky musí být ověřen proti organizaci faktury.',
        );

        $this->assertSame(
            [],
            array_values(array_filter(
                $reflection->getConstant('DRAFT_ITEM_ATTRIBUTES'),
                fn (string $attribute): bool => str_ends_with($attribute, '_id'),
            )),
            'Položky nesmí přijímat žádný cizí klíč — doplňuje je lifecycle služba.',
        );
    }

    public function test_each_foreign_reference_is_rejected_separately(): void
    {
        foreach ($this->foreignReferences() as $attribute => $foreignId) {
            $invoice = $this->draft();
            $before = IssuedInvoice::query()->findOrFail($invoice->id);

            try {
                $this->lifecycle->updateDraft($invoice, [$attribute => $foreignId], [$this->item()]);
                $this->fail("Cizí {$attribute} musí být odmítnuto.");
            } catch (InvalidInvoiceReference $e) {
                $this->assertStringContainsString($attribute, $e->getMessage());
            }

            $after = IssuedInvoice::query()->findOrFail($invoice->id);

            $this->assertSame(
                $before->getAttribute($attribute),
                $after->getAttribute($attribute),
                "Atribut {$attribute} se nesmí změnit.",
            );
            $this->assertSame($this->organization->id, (int) $after->organization_id);
        }
    }

    public function test_a_nonexistent_reference_is_rejected(): void
    {
        $invoice = $this->draft();

        $this->expectException(InvalidInvoiceReference::class);

        $this->lifecycle->updateDraft($invoice, ['contact_id' => 999999], [$this->item()]);
    }

    /**
     * Kombinovaný payload: jeden legitimní atribut, jedna cizí reference
     * a nové položky. Nesmí se zapsat NIC.
     */
    public function test_mixed_payload_with_a_foreign_reference_changes_nothing(): void
    {
        $invoice = $this->draft();
        $foreign = $this->foreignReferences();

        $before = IssuedInvoice::query()->findOrFail($invoice->id);
        $auditsBefore = AuditLog::query()->count();

        try {
            $this->lifecycle->updateDraft(
                $invoice,
                ['due_date' => '2027-01-01', 'note' => 'nová poznámka', 'contact_id' => $foreign['contact_id']],
                [$this->item('Nesmí se uložit'), $this->item('Ani tahle')],
            );
            $this->fail('Kombinovaný payload s cizí referencí musí selhat.');
        } catch (InvalidInvoiceReference) {
            // očekáváno
        }

        $after = IssuedInvoice::query()->findOrFail($invoice->id);

        $this->assertSame($before->due_date->toDateString(), $after->due_date->toDateString(), 'Hlavička se nezmění.');
        $this->assertSame($before->note, $after->note);
        $this->assertSame($before->contact_id, $after->contact_id);
        $this->assertSame((int) $before->total_minor, (int) $after->total_minor, 'Total se nepřepočítá.');

        $items = $after->items()->get();
        $this->assertCount(1, $items, 'Položky zůstávají původní.');
        $this->assertSame('Původní položka', $items->first()->description);

        $this->assertSame($auditsBefore, AuditLog::query()->count(), 'Nesmí vzniknout audit.');
        $this->assertSame($this->organization->id, (int) $after->organization_id);
    }

    public function test_audit_never_appears_under_the_foreign_organization(): void
    {
        $invoice = $this->draft();

        try {
            $this->lifecycle->updateDraft(
                $invoice,
                ['contact_id' => $this->foreignReferences()['contact_id']],
                [$this->item()],
            );
        } catch (InvalidInvoiceReference) {
            // očekáváno
        }

        $this->assertSame(0, AuditLog::query()->where('organization_id', $this->foreign->id)->count());
    }

    public function test_legitimate_references_of_the_same_organization_pass(): void
    {
        $invoice = $this->draft();

        $contact = Contact::factory()->create(['organization_id' => $this->organization->id]);
        $project = Project::factory()->create(['organization_id' => $this->organization->id]);
        $account = BankAccount::factory()->create([
            'organization_id' => $this->organization->id,
            'iban' => 'CZ6508000000192000145399',
        ]);
        $series = InvoiceNumberSeries::factory()->create([
            'organization_id' => $this->organization->id,
            'prefix' => 'FX',
        ]);

        $this->lifecycle->updateDraft($invoice, [
            'contact_id' => $contact->id,
            'project_id' => $project->id,
            'bank_account_id' => $account->id,
            'number_series_id' => $series->id,
            'due_date' => '2026-09-30',
        ], [$this->item()]);

        $fresh = IssuedInvoice::query()->findOrFail($invoice->id);

        $this->assertSame($contact->id, $fresh->contact_id);
        $this->assertSame($project->id, $fresh->project_id);
        $this->assertSame($account->id, $fresh->bank_account_id);
        $this->assertSame($series->id, $fresh->number_series_id);
        $this->assertSame(10000, (int) $fresh->total_minor);
    }

    public function test_null_references_are_allowed(): void
    {
        $invoice = $this->draft();

        $this->lifecycle->updateDraft(
            $invoice,
            ['project_id' => null, 'bank_account_id' => null],
            [$this->item()],
        );

        $fresh = IssuedInvoice::query()->findOrFail($invoice->id);

        $this->assertNull($fresh->project_id);
        $this->assertNull($fresh->bank_account_id);
    }

    /**
     * Vlastnictví se posuzuje proti faktuře, ne proti ambientnímu contextu:
     * i kdyby byl context nastavený na cizí organizaci, rozhoduje
     * organization_id zamčené faktury (a mutaci stejně zastaví tenant scope).
     */
    public function test_ownership_is_decided_by_the_invoice_not_the_ambient_context(): void
    {
        $invoice = $this->draft();
        $ownContact = Contact::factory()->create(['organization_id' => $this->organization->id]);

        app(CurrentOrganization::class)->set(null);

        $this->lifecycle->updateDraft($invoice, ['contact_id' => $ownContact->id], [$this->item()]);

        $this->assertSame(
            $ownContact->id,
            IssuedInvoice::query()->withoutGlobalScope('organization')->findOrFail($invoice->id)->contact_id,
        );
    }
}
