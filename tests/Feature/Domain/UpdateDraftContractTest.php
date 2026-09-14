<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Invoicing\IssuedInvoiceLifecycle;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\IssuedInvoiceStatus;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\InvoiceNumberSeries;
use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use App\Models\Organization;
use App\Models\OrganizationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * RE-REVIEW, nález „updateDraft() přijímá neomezené pole“: přes forceFill()
 * šlo změnit organization_id, invoice_number i další systémová pole.
 * Kontrakt je teď explicitní whitelist; neznámý klíč končí výjimkou
 * a NEZAPÍŠE se nic — ani legitimní část změny.
 */
class UpdateDraftContractTest extends TestCase
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
            'unit_price_minor' => 10000,
            'quantity' => '1',
            'vat_rate' => null,
        ]);

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private function legitimateItem(string $description = 'Nová položka'): array
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

    public function test_legitimate_draft_edit_passes(): void
    {
        $invoice = $this->draft();

        $this->lifecycle->updateDraft(
            $invoice,
            ['due_date' => '2026-09-30', 'note' => 'upraveno', 'variable_symbol' => '1234567890'],
            [$this->legitimateItem()],
        );

        $fresh = IssuedInvoice::query()->findOrFail($invoice->id);
        $this->assertSame('2026-09-30', $fresh->due_date->toDateString());
        $this->assertSame('upraveno', $fresh->note);
        $this->assertSame('1234567890', $fresh->variable_symbol);
        $this->assertSame(10000, (int) $fresh->total_minor, 'Součty se přepočítají z nových položek.');
        $this->assertSame('Nová položka', $fresh->items()->firstOrFail()->description);
    }

    public function test_organization_id_in_header_is_rejected(): void
    {
        $invoice = $this->draft();
        $foreign = Organization::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        $this->lifecycle->updateDraft($invoice, ['organization_id' => $foreign->id], [$this->legitimateItem()]);
    }

    public function test_invoice_number_in_header_is_rejected(): void
    {
        $invoice = $this->draft();

        $this->expectException(InvalidArgumentException::class);

        $this->lifecycle->updateDraft($invoice, ['invoice_number' => 'PODVRH-1'], [$this->legitimateItem()]);
    }

    public function test_status_in_header_is_rejected(): void
    {
        $invoice = $this->draft();

        $this->expectException(InvalidArgumentException::class);

        $this->lifecycle->updateDraft($invoice, ['status' => IssuedInvoiceStatus::Issued], [$this->legitimateItem()]);
    }

    public function test_paid_amount_in_header_is_rejected(): void
    {
        $invoice = $this->draft();

        $this->expectException(InvalidArgumentException::class);

        $this->lifecycle->updateDraft($invoice, ['paid_amount_minor' => 999999], [$this->legitimateItem()]);
    }

    public function test_lifecycle_and_snapshot_fields_in_header_are_rejected(): void
    {
        $invoice = $this->draft();

        foreach ([
            'id' => 999,
            'cancelled_at' => now(),
            'paid_at' => now(),
            'issued_at' => now(),
            'logo_snapshot_path' => 'invoice-logos/org-1/podvrh.png',
            'supplier_snapshot' => ['name' => 'Podvrh'],
            'created_at' => now(),
            'updated_at' => now(),
        ] as $attribute => $value) {
            try {
                $this->lifecycle->updateDraft($invoice, [$attribute => $value], [$this->legitimateItem()]);
                $this->fail("Atribut {$attribute} musí být odmítnut.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($attribute, $e->getMessage());
            }
        }
    }

    public function test_mixing_legitimate_and_forbidden_attributes_rolls_back_everything(): void
    {
        $invoice = $this->draft();
        $originalDueDate = $invoice->due_date->toDateString();
        $originalNote = $invoice->note;

        try {
            $this->lifecycle->updateDraft(
                $invoice,
                ['due_date' => '2027-01-01', 'organization_id' => Organization::factory()->create()->id],
                [$this->legitimateItem('Nesmí se uložit')],
            );
            $this->fail('Kombinace legitimního a zakázaného atributu musí selhat.');
        } catch (InvalidArgumentException) {
            // očekáváno
        }

        $fresh = IssuedInvoice::query()->findOrFail($invoice->id);
        $this->assertSame($originalDueDate, $fresh->due_date->toDateString(), 'Legitimní část se nesmí zapsat.');
        $this->assertSame($originalNote, $fresh->note);
        $this->assertNotSame('Nesmí se uložit', $fresh->items()->firstOrFail()->description);
    }

    public function test_forbidden_attributes_in_items_are_rejected(): void
    {
        $invoice = $this->draft();
        $originalItem = $invoice->items()->firstOrFail()->description;

        foreach ([
            'organization_id' => 999,
            'issued_invoice_id' => 999,
            'position' => 99,
            'id' => 999,
        ] as $attribute => $value) {
            try {
                $this->lifecycle->updateDraft($invoice, [], [$this->legitimateItem() + [$attribute => $value]]);
                $this->fail("Atribut položky {$attribute} musí být odmítnut.");
            } catch (InvalidArgumentException) {
                // očekáváno
            }
        }

        $this->assertSame(
            $originalItem,
            IssuedInvoice::query()->findOrFail($invoice->id)->items()->firstOrFail()->description,
            'Položky se nesmí přepsat.',
        );
    }

    public function test_draft_stays_under_its_original_organization(): void
    {
        $invoice = $this->draft();
        $foreign = Organization::factory()->create();

        try {
            $this->lifecycle->updateDraft(
                $invoice,
                ['organization_id' => $foreign->id, 'due_date' => '2027-01-01'],
                [$this->legitimateItem()],
            );
        } catch (InvalidArgumentException) {
            // očekáváno
        }

        $this->assertSame(
            $this->organization->id,
            (int) IssuedInvoice::query()->findOrFail($invoice->id)->organization_id,
        );
        $this->assertSame(
            0,
            AuditLog::query()->where('organization_id', $foreign->id)->count(),
            'Pod cizí organizací nesmí vzniknout žádný audit.',
        );
    }
}
