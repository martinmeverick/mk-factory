<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pdf\InvoicePdfDataFactory;
use App\Enums\ContactType;
use App\Enums\InvoiceRecipientMode;
use App\Enums\IssuedInvoiceStatus;
use App\Enums\VatRegime;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\InvoiceNumberSeries;
use App\Models\IssuedInvoice;
use App\Models\Organization;
use App\Models\OrganizationSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Přímé zadání fyzické osoby jako odběratele ve formuláři vydané faktury:
 * validace → kontakt vzniká ve stejné transakci jako koncept → snapshot
 * při vystavení → doklad bez IČO/DIČ; zpětná kompatibilita výběru z kontaktů;
 * žádné osiřelé kontakty při selhání.
 */
class InlinePrivateCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private OrganizationSettings $settings;

    private Contact $contact;

    private InvoiceNumberSeries $series;

    private BankAccount $bank;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['ico' => '12345678', 'dic' => 'CZ12345678']);
        $this->bank = BankAccount::factory()->create([
            'organization_id' => $this->org->id,
            'iban' => 'CZ1801000000000123456789',
            'is_default' => true,
        ]);
        $this->series = InvoiceNumberSeries::factory()->create(['organization_id' => $this->org->id]);
        $this->settings = OrganizationSettings::factory()->create([
            'organization_id' => $this->org->id,
            'vat_payer' => true,
            'default_bank_account_id' => $this->bank->id,
            'default_number_series_id' => $this->series->id,
        ]);
        $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Firma s.r.o.']);

        $this->user = User::factory()->create();
        $this->user->organizations()->attach($this->org, ['role' => 'owner']);
    }

    private function actingInOrg(): static
    {
        return $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides  null = klíč odebrat
     * @return array<string, mixed>
     */
    private function person(array $overrides = []): array
    {
        return array_filter(array_replace([
            'name' => 'Jana Nováková',
            'street' => 'Dlouhá 12',
            'city' => 'Brno',
            'zip' => '602 00',
            'country' => 'CZ',
            'email' => 'jana.novakova@example.com',
        ], $overrides), fn ($value) => $value !== null);
    }

    /**
     * Běžný režim plátce, výběr z kontaktů (bez recipient_mode = dosavadní API).
     *
     * @param  array<string, mixed>  $overrides  null = klíč odebrat
     * @param  array<string, mixed>  $item  null = klíč odebrat
     * @return array<string, mixed>
     */
    private function existingPayload(array $overrides = [], array $item = []): array
    {
        $line = array_replace([
            'description' => 'Oprava displeje',
            'quantity' => '1',
            'unit' => 'ks',
            'unit_price' => '1000',
            'vat_rate' => '21',
        ], $item);

        $payload = array_replace([
            'contact_id' => $this->contact->id,
            'number_series_id' => $this->series->id,
            'bank_account_id' => $this->bank->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'tax_date' => now()->toDateString(),
            'vat_regime' => VatRegime::Standard->value,
            'items' => [array_filter($line, fn ($value) => $value !== null)],
        ], $overrides);

        return array_filter($payload, fn ($value) => $value !== null);
    }

    /**
     * Ruční zadání fyzické osoby (bez contact_id).
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $personOverrides
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function manualPayload(array $overrides = [], array $personOverrides = [], array $item = []): array
    {
        return $this->existingPayload(array_replace([
            'contact_id' => null,
            'recipient_mode' => InvoiceRecipientMode::Manual->value,
            'person' => $this->person($personOverrides),
        ], $overrides), $item);
    }

    /**
     * Zvláštní režim - použité zboží s ručně zadanou osobou.
     *
     * @return array<string, mixed>
     */
    private function manualMarginPayload(): array
    {
        return $this->manualPayload(
            ['vat_regime' => VatRegime::UsedGoodsMargin->value, 'margin_vat_rate' => '21'],
            [],
            ['description' => 'iPhone 13 128 GB, použitý', 'unit_price' => '1210', 'vat_rate' => null, 'acquisition_unit_price' => '1000'],
        );
    }

    /** Položka, jejíž přepočet přeteče rozsah Money (viz MoneyOverflowTest). */
    private function overflowItem(): array
    {
        return ['unit_price' => '9999999999.99', 'quantity' => '999999999.999'];
    }

    private function storedInvoice(): IssuedInvoice
    {
        return IssuedInvoice::withoutGlobalScope('organization')->firstOrFail();
    }

    private function contactsCount(): int
    {
        return Contact::withoutGlobalScope('organization')->count();
    }

    private function newestContact(): Contact
    {
        return Contact::withoutGlobalScope('organization')->orderByDesc('id')->firstOrFail();
    }

    private function renderedPdf(IssuedInvoice $invoice): string
    {
        $data = app(InvoicePdfDataFactory::class)->fromInvoice(
            IssuedInvoice::withoutGlobalScope('organization')->findOrFail($invoice->id),
        );

        return view('pdf.invoice', ['data' => $data])->render();
    }

    /** Blok „Odběratel“ z HTML dokladu. */
    private function customerBlock(string $html): string
    {
        $this->assertSame(1, preg_match('#<td class="party">\s*<span class="label">Odběratel</span>(.*?)</td>#su', $html, $match));

        return $match[1];
    }

    // ---------- založení konceptu ----------

    public function test_manual_person_creates_customer_contact_with_standard_draft(): void
    {
        $response = $this->actingInOrg()->post(route('invoices.store'), $this->manualPayload());

        $invoice = $this->storedInvoice();
        $response->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame(2, $this->contactsCount());
        $person = $this->newestContact();

        // Organizace i typ z aplikace, nikdy ze vstupu; IČO/DIČ se nevyžadují a zůstávají null.
        $this->assertSame($this->org->id, $person->organization_id);
        $this->assertSame(ContactType::Customer, $person->type);
        $this->assertSame('Jana Nováková', $person->name);
        $this->assertSame('Dlouhá 12', $person->street);
        $this->assertSame('Brno', $person->city);
        $this->assertSame('602 00', $person->zip);
        $this->assertSame('CZ', $person->country);
        $this->assertSame('jana.novakova@example.com', $person->email);
        $this->assertNull($person->ico);
        $this->assertNull($person->dic);
        $this->assertNull($person->external_id);
        $this->assertNull($person->phone);
        $this->assertNull($person->note);

        $this->assertSame($person->id, $invoice->contact_id);
        $this->assertSame(IssuedInvoiceStatus::Draft, $invoice->status);
        $this->assertSame(VatRegime::Standard, $invoice->vat_regime);
        $this->assertSame(100000, $invoice->subtotal_minor);
        $this->assertSame(21000, $invoice->vat_total_minor);
        $this->assertSame(121000, $invoice->total_minor);
    }

    public function test_manual_person_creates_customer_contact_with_margin_draft(): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->manualMarginPayload())
            ->assertSessionHasNoErrors();

        $invoice = $this->storedInvoice();
        $person = $this->newestContact();

        $this->assertSame(2, $this->contactsCount());
        $this->assertSame($person->id, $invoice->contact_id);
        $this->assertSame(ContactType::Customer, $person->type);
        $this->assertNull($person->ico);

        // Evidence zvláštního režimu zůstává nedotčená.
        $this->assertSame(VatRegime::UsedGoodsMargin, $invoice->vat_regime);
        $this->assertSame('21.00', (string) $invoice->margin_vat_rate);
        $this->assertSame(121000, $invoice->total_minor);
        $this->assertSame(0, $invoice->vat_total_minor);
        $this->assertSame(3645, $invoice->margin_vat_minor);
        $this->assertSame(17355, $invoice->margin_base_minor);
        $this->assertSame(100000, $invoice->items()->firstOrFail()->acquisition_unit_price_minor);
    }

    public function test_email_is_optional_and_country_is_normalized(): void
    {
        $this->actingInOrg()
            ->post(route('invoices.store'), $this->manualPayload([], ['email' => null, 'country' => 'cz']))
            ->assertSessionHasNoErrors();

        $person = $this->newestContact();
        $this->assertNull($person->email);
        $this->assertSame('CZ', $person->country);
    }

    public function test_same_name_creates_separate_contacts(): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->manualPayload());
        $this->actingInOrg()->post(route('invoices.store'), $this->manualPayload());

        $this->assertSame(3, $this->contactsCount());
        $this->assertSame(2, Contact::withoutGlobalScope('organization')->where('name', 'Jana Nováková')->count());

        $contactIds = IssuedInvoice::withoutGlobalScope('organization')->pluck('contact_id')->all();
        $this->assertCount(2, array_unique($contactIds));
    }

    // ---------- vystavení, snapshot a doklad ----------

    public function test_issue_freezes_person_in_snapshot_and_pdf_without_business_ids(): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->manualPayload());
        $invoice = $this->storedInvoice();

        $this->actingInOrg()->post(route('invoices.issue', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('status');

        $invoice->refresh();
        $this->assertSame(IssuedInvoiceStatus::Issued, $invoice->status);

        $snapshot = $invoice->customer_snapshot;
        $this->assertSame('Jana Nováková', $snapshot['name']);
        $this->assertSame('Dlouhá 12', $snapshot['street']);
        $this->assertSame('Brno', $snapshot['city']);
        $this->assertSame('602 00', $snapshot['zip']);
        $this->assertSame('CZ', $snapshot['country']);
        $this->assertSame('jana.novakova@example.com', $snapshot['email']);
        $this->assertNull($snapshot['ico']);
        $this->assertNull($snapshot['dic']);

        $block = $this->customerBlock($this->renderedPdf($invoice));
        $this->assertStringContainsString('Jana Nováková', $block);
        $this->assertStringContainsString('Dlouhá 12', $block);
        $this->assertStringContainsString('602 00 Brno', $block);
        $this->assertStringNotContainsString('IČO', $block);
        $this->assertStringNotContainsString('DIČ', $block);

        // Pozdější úprava kontaktu historický doklad nemění.
        Contact::withoutGlobalScope('organization')->findOrFail($invoice->contact_id)
            ->update(['name' => 'Jana Přejmenovaná', 'street' => 'Jiná 1', 'ico' => '87654321']);

        $fresh = IssuedInvoice::withoutGlobalScope('organization')->findOrFail($invoice->id);
        $this->assertSame('Jana Nováková', $fresh->customer_snapshot['name']);

        $block = $this->customerBlock($this->renderedPdf($fresh));
        $this->assertStringContainsString('Jana Nováková', $block);
        $this->assertStringContainsString('Dlouhá 12', $block);
        $this->assertStringNotContainsString('Přejmenovaná', $block);
        $this->assertStringNotContainsString('IČO', $block);

        $this->actingInOrg()->get(route('invoices.pdf', $invoice))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // ---------- zpětná kompatibilita ----------

    public function test_existing_contact_works_without_and_with_explicit_mode(): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->existingPayload())
            ->assertSessionHasNoErrors();

        $this->actingInOrg()
            ->post(route('invoices.store'), $this->existingPayload(['recipient_mode' => InvoiceRecipientMode::Existing->value]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->contactsCount());
        $this->assertSame(2, IssuedInvoice::withoutGlobalScope('organization')->where('contact_id', $this->contact->id)->count());
    }

    // ---------- validace ----------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}>
     */
    public static function invalidManualInput(): array
    {
        return [
            'unknown mode' => [['recipient_mode' => 'company'], [], 'recipient_mode'],
            'array mode' => [['recipient_mode' => ['manual']], [], 'recipient_mode'],
            'person as string' => [['person' => 'Jana Nováková'], [], 'person'],
            'missing name' => [[], ['name' => null], 'person.name'],
            'blank name' => [[], ['name' => '   '], 'person.name'],
            'array name' => [[], ['name' => ['Jana']], 'person.name'],
            'name too long' => [[], ['name' => str_repeat('a', 256)], 'person.name'],
            'missing street' => [[], ['street' => null], 'person.street'],
            'missing city' => [[], ['city' => null], 'person.city'],
            'missing zip' => [[], ['zip' => null], 'person.zip'],
            'zip too long' => [[], ['zip' => str_repeat('1', 21)], 'person.zip'],
            'country word' => [[], ['country' => 'Czechia'], 'person.country'],
            'country with digit' => [[], ['country' => 'C1'], 'person.country'],
            'array country' => [[], ['country' => ['CZ']], 'person.country'],
            'invalid email' => [[], ['email' => 'not-an-email'], 'person.email'],
            'array email' => [[], ['email' => ['a@example.com']], 'person.email'],
        ];
    }

    #[DataProvider('invalidManualInput')]
    public function test_invalid_manual_input_is_rejected_without_writes(array $overrides, array $person, string $errorKey): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->manualPayload($overrides, $person))
            ->assertSessionHasErrors($errorKey);

        $this->assertSame(0, IssuedInvoice::withoutGlobalScope('organization')->count());
        $this->assertSame(1, $this->contactsCount());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}>
     */
    public static function conflictingOrHostileInput(): array
    {
        return [
            'manual with contact_id' => [['contact_id' => '__CONTACT__'], [], 'contact_id'],
            'existing with person fields' => [['recipient_mode' => 'existing', 'contact_id' => '__CONTACT__'], [], 'person.name'],
            'blank mode with person fields' => [['recipient_mode' => '', 'contact_id' => '__CONTACT__'], [], 'person.name'],
            'person with ico' => [[], ['ico' => '12345678'], 'person.ico'],
            'person with dic' => [[], ['dic' => 'CZ12345678'], 'person.dic'],
            'person with external_id' => [[], ['external_id' => 'X-1'], 'person.external_id'],
            'person with type' => [[], ['type' => 'supplier'], 'person.type'],
            'person with organization_id' => [[], ['organization_id' => 999], 'person.organization_id'],
            'person with id' => [[], ['id' => 1], 'person.id'],
            'person with unknown key' => [[], ['phone' => '777 123 456'], 'person'],
        ];
    }

    #[DataProvider('conflictingOrHostileInput')]
    public function test_conflicting_or_hostile_input_is_rejected_without_writes(array $overrides, array $person, string $errorKey): void
    {
        $overrides = array_map(fn ($value) => $value === '__CONTACT__' ? $this->contact->id : $value, $overrides);

        $this->actingInOrg()->post(route('invoices.store'), $this->manualPayload($overrides, $person))
            ->assertSessionHasErrors($errorKey);

        $this->assertSame(0, IssuedInvoice::withoutGlobalScope('organization')->count());
        $this->assertSame(1, $this->contactsCount());
    }

    public function test_foreign_contact_is_rejected_in_existing_mode(): void
    {
        $orgB = Organization::factory()->create();
        $contactB = Contact::factory()->create(['organization_id' => $orgB->id]);

        $this->actingInOrg()
            ->post(route('invoices.store'), $this->existingPayload([
                'recipient_mode' => InvoiceRecipientMode::Existing->value,
                'contact_id' => $contactB->id,
            ]))
            ->assertSessionHasErrors('contact_id');

        $this->assertSame(0, IssuedInvoice::withoutGlobalScope('organization')->count());
    }

    public function test_hostile_organization_id_in_person_cannot_change_tenant(): void
    {
        $orgB = Organization::factory()->create();

        $this->actingInOrg()
            ->post(route('invoices.store'), $this->manualPayload([], ['organization_id' => $orgB->id]))
            ->assertSessionHasErrors('person.organization_id');

        $this->assertSame(0, Contact::withoutGlobalScope('organization')->where('organization_id', $orgB->id)->count());
        $this->assertSame(1, $this->contactsCount());
    }

    // ---------- atomicita ----------

    public function test_store_overflow_creates_neither_contact_nor_invoice(): void
    {
        $this->actingInOrg()
            ->from(route('invoices.create'))
            ->post(route('invoices.store'), $this->manualPayload([], [], $this->overflowItem()))
            ->assertRedirect(route('invoices.create'))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, IssuedInvoice::withoutGlobalScope('organization')->count());
        $this->assertSame(1, $this->contactsCount());
    }

    public function test_update_of_issued_invoice_with_new_person_leaves_no_orphan_contact(): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->existingPayload());
        $invoice = $this->storedInvoice();
        $this->actingInOrg()->post(route('invoices.issue', $invoice))->assertSessionHas('status');

        $this->actingInOrg()->put(route('invoices.update', $invoice), $this->manualPayload())
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('error');

        $this->assertSame(1, $this->contactsCount());

        $invoice->refresh();
        $this->assertSame(IssuedInvoiceStatus::Issued, $invoice->status);
        $this->assertSame($this->contact->id, $invoice->contact_id);
        $this->assertSame('Firma s.r.o.', $invoice->customer_snapshot['name']);
    }

    public function test_update_overflow_rolls_back_new_contact_and_keeps_original_draft(): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->existingPayload());
        $invoice = $this->storedInvoice();

        $this->actingInOrg()
            ->put(route('invoices.update', $invoice), $this->manualPayload([], [], $this->overflowItem()))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('error');

        $this->assertSame(1, $this->contactsCount());

        $invoice->refresh();
        $this->assertSame($this->contact->id, $invoice->contact_id);
        $this->assertSame(121000, $invoice->total_minor);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame('Oprava displeje', $invoice->items()->firstOrFail()->description);
        $this->assertSame(100000, $invoice->items()->firstOrFail()->unit_price_minor);
    }

    // ---------- úprava konceptu ----------

    public function test_editing_manual_draft_keeps_saved_contact_without_duplicates(): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->manualPayload());
        $invoice = $this->storedInvoice();
        $person = $this->newestContact();

        // Formulář úpravy nabízí uloženou osobu jako vybraný kontakt.
        $html = $this->actingInOrg()->get(route('invoices.edit', $invoice))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="recipient_mode_existing"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="recipient_mode_manual"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/<option value="'.$person->id.'"\s+selected/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="contact_id"[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/name="person\[name\]"[^>]*disabled/', $html);

        $this->actingInOrg()
            ->put(route('invoices.update', $invoice), $this->existingPayload([
                'recipient_mode' => InvoiceRecipientMode::Existing->value,
                'contact_id' => $person->id,
                'note' => 'Upraveno',
            ]))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('status');

        // Beze změny způsobu zadání (dosavadní API) rovněž žádný duplikát.
        $this->actingInOrg()
            ->put(route('invoices.update', $invoice), $this->existingPayload(['contact_id' => $person->id]))
            ->assertSessionHas('status');

        $this->assertSame(2, $this->contactsCount());
        $this->assertSame($person->id, $invoice->refresh()->contact_id);
        $this->assertSame('Jana Nováková', $person->refresh()->name);
    }

    public function test_editing_can_switch_to_new_person_without_touching_shared_contact(): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->existingPayload());
        $invoice = $this->storedInvoice();
        $other = IssuedInvoice::factory()->draft()->create([
            'organization_id' => $this->org->id,
            'contact_id' => $this->contact->id,
        ]);

        $this->actingInOrg()
            ->put(route('invoices.update', $invoice), $this->manualPayload([], ['name' => 'Petr Svoboda']))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('status');

        $this->assertSame(2, $this->contactsCount());
        $person = $this->newestContact();
        $this->assertSame('Petr Svoboda', $person->name);
        $this->assertSame(ContactType::Customer, $person->type);
        $this->assertSame($person->id, $invoice->refresh()->contact_id);

        // Sdílený kontakt ani druhá faktura se nezměnily.
        $this->assertSame('Firma s.r.o.', $this->contact->refresh()->name);
        $this->assertSame($this->contact->id, $other->refresh()->contact_id);
    }

    // ---------- formulář ----------

    public function test_create_form_offers_both_choices_and_explains_saving(): void
    {
        $html = $this->actingInOrg()->get(route('invoices.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Vybrat z kontaktů', $html);
        $this->assertStringContainsString('Zadat fyzickou osobu', $html);
        $this->assertStringContainsString('uloží se do kontaktů', $html);
        $this->assertMatchesRegularExpression('/id="recipient_mode_existing"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="contact_id"[^>]*disabled/', $html);
        foreach (['name', 'street', 'city', 'zip', 'country', 'email'] as $field) {
            $this->assertMatchesRegularExpression('/name="person\['.$field.'\]"[^>]*disabled/', $html);
        }
        $this->assertStringContainsString('value="CZ"', $html);
        $this->assertStringNotContainsString('person[ico]', $html);
    }

    public function test_validation_error_rerender_keeps_person_mode_and_margin_inputs(): void
    {
        $payload = $this->manualMarginPayload();
        $payload['person']['email'] = 'not-an-email';

        $this->actingInOrg()
            ->from(route('invoices.create'))
            ->post(route('invoices.store'), $payload)
            ->assertRedirect(route('invoices.create'))
            ->assertSessionHasErrors('person.email');

        $html = $this->actingInOrg()->get(route('invoices.create'))->assertOk()->getContent();

        // Ruční zadání zůstává zvolené a aktivní, výběr z kontaktů zakázaný.
        $this->assertMatchesRegularExpression('/id="recipient_mode_manual"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="contact_id"[^>]*disabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="person\[name\]"[^>]*disabled/', $html);
        $this->assertStringContainsString('value="Jana Nováková"', $html);
        $this->assertStringContainsString('value="Dlouhá 12"', $html);
        $this->assertStringContainsString('value="602 00"', $html);
        $this->assertStringContainsString('value="not-an-email"', $html);

        // Zvláštní režim a jeho položky zůstávají zachované.
        $this->assertStringContainsString('value="used_goods_margin" selected', $html);
        $this->assertStringContainsString('value="1210"', $html);
        $this->assertStringContainsString('value="1000"', $html);
        $this->assertStringContainsString('value="iPhone 13 128 GB, použitý"', $html);
        $this->assertDoesNotMatchRegularExpression('/name="items\[0\]\[acquisition_unit_price\]"[^>]*disabled/', $html);

        $this->assertSame(0, IssuedInvoice::withoutGlobalScope('organization')->count());
        $this->assertSame(1, $this->contactsCount());
    }
}
