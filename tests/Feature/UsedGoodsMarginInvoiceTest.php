<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Domain\Invoicing\InvoiceNotIssuable;
use App\Domain\Invoicing\IssuedInvoiceLifecycle;
use App\Domain\Pdf\InvoicePdfData;
use App\Domain\Pdf\InvoicePdfDataFactory;
use App\Domain\Pdf\InvoicePdfLine;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\IssuedInvoiceStatus;
use App\Enums\VatRegime;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\InvoiceNumberSeries;
use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use App\Models\Organization;
use App\Models\OrganizationSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Zvláštní režim - použité zboží (§ 90 ZDPH) — HTTP tok: formulář →
 * validace → koncept → vystavení → doklad odběratele (HTML šablony PDF)
 * + regrese běžného režimu plátce/neplátce a izolace organizací.
 */
class UsedGoodsMarginInvoiceTest extends TestCase
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
        $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);

        $this->user = User::factory()->create();
        $this->user->organizations()->attach($this->org, ['role' => 'owner']);
    }

    private function actingInOrg(): static
    {
        return $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id]);
    }

    /**
     * Platný požadavek zvláštního režimu: použitý telefon, konečná cena
     * 1 210 Kč, pořizovací 1 000 Kč.
     *
     * @param  array<string, mixed>  $overrides  přepisy hlavičky (null = klíč odebrat)
     * @param  array<string, mixed>  $item  přepisy položky (null = klíč odebrat)
     */
    private function marginPayload(array $overrides = [], array $item = []): array
    {
        $line = array_replace([
            'description' => 'iPhone 13 128 GB, použitý',
            'quantity' => '1',
            'unit' => 'ks',
            'unit_price' => '1210',
            'acquisition_unit_price' => '1000',
        ], $item);

        $payload = array_replace([
            'contact_id' => $this->contact->id,
            'number_series_id' => $this->series->id,
            'bank_account_id' => $this->bank->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'tax_date' => now()->toDateString(),
            'vat_regime' => VatRegime::UsedGoodsMargin->value,
            'margin_vat_rate' => '21',
            'items' => [array_filter($line, fn ($value) => $value !== null)],
        ], $overrides);

        return array_filter($payload, fn ($value) => $value !== null);
    }

    private function storedInvoice(): IssuedInvoice
    {
        return IssuedInvoice::withoutGlobalScope('organization')->firstOrFail();
    }

    private function customerDocument(IssuedInvoice $invoice): InvoicePdfData
    {
        return app(InvoicePdfDataFactory::class)->fromInvoice(
            IssuedInvoice::withoutGlobalScope('organization')->findOrFail($invoice->id),
        );
    }

    /** HTML bez bloků <style> a <script> a bez komentářů (značky a atributy zůstávají). */
    private function markupWithoutStyles(string $html): string
    {
        $markup = preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;

        return preg_replace('#<!--.*?-->#s', '', $markup) ?? $markup;
    }

    /** Viditelný text dokladu: bez CSS/skriptů, bez značek, s dekódovanými entitami. */
    private function visibleText(string $html): string
    {
        $text = html_entity_decode(strip_tags($this->markupWithoutStyles($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/[ \t\r\n]+/u', ' ', $text) ?? $text;
    }

    // ---------- uložení konceptu ----------

    public function test_margin_draft_is_stored_with_internal_margin_evidence(): void
    {
        $response = $this->actingInOrg()->post(route('invoices.store'), $this->marginPayload());

        $invoice = $this->storedInvoice();
        $response->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame(VatRegime::UsedGoodsMargin, $invoice->vat_regime);
        $this->assertSame('21.00', (string) $invoice->margin_vat_rate);
        $this->assertSame(IssuedInvoiceStatus::Draft, $invoice->status);

        // Částky pro odběratele: subtotal = total = konečná cena, běžná DPH 0.
        $this->assertSame(121000, $invoice->subtotal_minor);
        $this->assertSame(0, $invoice->vat_total_minor);
        $this->assertSame(121000, $invoice->total_minor);

        // Interní evidence přirážky — oddělená od subtotal/vat_total.
        $this->assertSame(100000, $invoice->margin_acquisition_total_minor);
        $this->assertSame(21000, $invoice->margin_gross_minor);
        $this->assertSame(3645, $invoice->margin_vat_minor);
        $this->assertSame(17355, $invoice->margin_base_minor);

        $item = $invoice->items()->firstOrFail();
        $this->assertNull($item->vat_rate);
        $this->assertSame('1.000', (string) $item->quantity);
        $this->assertSame(121000, $item->unit_price_minor);
        $this->assertSame(100000, $item->acquisition_unit_price_minor);
        $this->assertSame(0, $item->line_vat_minor);
        $this->assertSame(121000, $item->line_total_minor);
        $this->assertSame(3645, $item->line_margin_vat_minor);
        $this->assertSame(17355, $item->line_margin_base_minor);
    }

    public function test_negative_margin_keeps_customer_total_and_zero_internal_tax(): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->marginPayload(item: ['acquisition_unit_price' => '1500']));

        $invoice = $this->storedInvoice();

        $this->assertSame(121000, $invoice->total_minor);
        $this->assertSame(150000, $invoice->margin_acquisition_total_minor);
        $this->assertSame(0, $invoice->margin_gross_minor);
        $this->assertSame(0, $invoice->margin_vat_minor);
        $this->assertSame(0, $invoice->margin_base_minor);
    }

    public function test_margin_regime_is_rejected_for_non_vat_payer_organization(): void
    {
        $this->settings->update(['vat_payer' => false]);

        $this->actingInOrg()->post(route('invoices.store'), $this->marginPayload())
            ->assertSessionHasErrors('vat_regime');

        $this->assertSame(0, IssuedInvoice::withoutGlobalScope('organization')->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidMarginItems(): array
    {
        return [
            'missing acquisition cost' => [['acquisition_unit_price' => null], 'items.0.acquisition_unit_price'],
            'empty acquisition cost' => [['acquisition_unit_price' => ''], 'items.0.acquisition_unit_price'],
            'negative acquisition cost' => [['acquisition_unit_price' => '-1'], 'items.0.acquisition_unit_price'],
            'three-decimal acquisition cost' => [['acquisition_unit_price' => '1000.123'], 'items.0.acquisition_unit_price'],
            'fractional quantity' => [['quantity' => '1.5'], 'items.0.quantity'],
            'zero quantity' => [['quantity' => '0'], 'items.0.quantity'],
            'negative quantity' => [['quantity' => '-1'], 'items.0.quantity'],
            'negative selling price' => [['unit_price' => '-1210'], 'items.0.unit_price'],
            'ordinary vat rate submitted' => [['vat_rate' => '21'], 'items.0.vat_rate'],
        ];
    }

    #[DataProvider('invalidMarginItems')]
    public function test_margin_item_validation_rejects_invalid_input(array $item, string $errorKey): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->marginPayload(item: $item))
            ->assertSessionHasErrors($errorKey);

        $this->assertSame(0, IssuedInvoice::withoutGlobalScope('organization')->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidMarginHeaders(): array
    {
        return [
            'unsupported margin rate' => [['margin_vat_rate' => '12'], 'margin_vat_rate'],
            'missing margin rate' => [['margin_vat_rate' => null], 'margin_vat_rate'],
            'unknown regime' => [['vat_regime' => 'reverse_charge'], 'vat_regime'],
        ];
    }

    #[DataProvider('invalidMarginHeaders')]
    public function test_margin_header_validation_rejects_invalid_input(array $overrides, string $errorKey): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->marginPayload($overrides))
            ->assertSessionHasErrors($errorKey);

        $this->assertSame(0, IssuedInvoice::withoutGlobalScope('organization')->count());
    }

    public function test_standard_regime_rejects_margin_only_fields(): void
    {
        $payload = $this->marginPayload(['vat_regime' => VatRegime::Standard->value]);

        $this->actingInOrg()->post(route('invoices.store'), $payload)
            ->assertSessionHasErrors(['margin_vat_rate', 'items.0.acquisition_unit_price']);

        $this->assertSame(0, IssuedInvoice::withoutGlobalScope('organization')->count());
    }

    public function test_validation_error_rerender_keeps_selected_regime_and_inputs(): void
    {
        $this->actingInOrg()
            ->from(route('invoices.create'))
            ->post(route('invoices.store'), $this->marginPayload(item: ['acquisition_unit_price' => '']))
            ->assertRedirect(route('invoices.create'))
            ->assertSessionHasErrors('items.0.acquisition_unit_price');

        $html = $this->actingInOrg()->get(route('invoices.create'))->assertOk()->getContent();

        // Zvolený režim zůstává vybraný, pole zvláštního režimu aktivní, sazba DPH zakázaná.
        $this->assertStringContainsString('value="used_goods_margin" selected', $html);
        $this->assertStringContainsString('name="margin_vat_rate"', $html);
        $this->assertStringContainsString('value="1210"', $html);
        $this->assertStringContainsString('value="iPhone 13 128 GB, použitý"', $html);
        $this->assertMatchesRegularExpression('/name="items\[0\]\[vat_rate\]"[^>]*disabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="items\[0\]\[acquisition_unit_price\]"[^>]*disabled/', $html);
        $this->assertStringContainsString('pořizovací cenu', $html);
    }

    public function test_create_form_shows_regime_choice_only_for_vat_payer(): void
    {
        $html = $this->actingInOrg()->get(route('invoices.create'))->assertOk()->getContent();
        $this->assertStringContainsString('name="vat_regime"', $html);
        $this->assertStringContainsString('Zvláštní režim - použité zboží', $html);

        $this->settings->update(['vat_payer' => false]);

        $html = $this->actingInOrg()->get(route('invoices.create'))->assertOk()->getContent();
        $this->assertStringContainsString('name="vat_regime" value="standard"', $html);
        $this->assertStringNotContainsString('id="vat_regime"', $html);
        $this->assertStringNotContainsString('acquisition_unit_price', $html);
    }

    public function test_update_switching_regime_to_standard_drops_margin_evidence(): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->marginPayload());
        $invoice = $this->storedInvoice();

        $standard = $this->marginPayload(
            ['vat_regime' => VatRegime::Standard->value, 'margin_vat_rate' => null],
            ['acquisition_unit_price' => null, 'unit_price' => '1000', 'vat_rate' => '21'],
        );

        $this->actingInOrg()->put(route('invoices.update', $invoice), $standard)
            ->assertRedirect(route('invoices.show', $invoice));

        $invoice->refresh();
        $this->assertSame(VatRegime::Standard, $invoice->vat_regime);
        $this->assertNull($invoice->margin_vat_rate);
        $this->assertSame(100000, $invoice->subtotal_minor);
        $this->assertSame(21000, $invoice->vat_total_minor);
        $this->assertSame(121000, $invoice->total_minor);
        $this->assertSame(0, $invoice->margin_vat_minor);
        $this->assertSame(0, $invoice->margin_base_minor);
        $this->assertNull($invoice->items()->firstOrFail()->acquisition_unit_price_minor);
    }

    // ---------- vystavení a neměnnost ----------

    public function test_issue_freezes_margin_fields_and_survives_later_settings_change(): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->marginPayload());
        $invoice = $this->storedInvoice();

        $this->actingInOrg()->post(route('invoices.issue', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('status');

        $invoice->refresh();
        $this->assertSame(IssuedInvoiceStatus::Issued, $invoice->status);
        $this->assertSame('FV20260001', $invoice->invoice_number);
        $this->assertSame('20260001', $invoice->variable_symbol);
        $this->assertSame(VatRegime::UsedGoodsMargin, $invoice->vat_regime);
        $this->assertSame(3645, $invoice->margin_vat_minor);
        $this->assertSame('CZ12345678', $invoice->supplier_snapshot['dic']);

        $log = AuditLog::query()->where('action', 'invoice.issued')->firstOrFail();
        $this->assertSame('used_goods_margin', $log->changes['vat_regime']);

        // Režim i interní evidence jsou po vystavení neměnné.
        $protected = [
            'vat_regime' => VatRegime::Standard,
            'margin_vat_rate' => null,
            'margin_vat_minor' => 0,
            'margin_base_minor' => 1,
            'margin_gross_minor' => 1,
            'margin_acquisition_total_minor' => 1,
        ];

        foreach ($protected as $attribute => $value) {
            try {
                IssuedInvoice::withoutGlobalScope('organization')->findOrFail($invoice->id)->update([$attribute => $value]);
                $this->fail("Očekávána ImmutableInvoiceViolation pro {$attribute}.");
            } catch (ImmutableInvoiceViolation) {
                // očekáváno
            }
        }

        // Pozdější změna nastavení organizace historický doklad nemění.
        $this->settings->update(['vat_payer' => false]);

        $fresh = IssuedInvoice::withoutGlobalScope('organization')->findOrFail($invoice->id);
        $this->assertSame(VatRegime::UsedGoodsMargin, $fresh->vat_regime);
        $this->assertSame(3645, $fresh->margin_vat_minor);

        $html = view('pdf.invoice', ['data' => $this->customerDocument($fresh)])->render();
        $this->assertStringContainsString('zvláštní režim - použité zboží', $html);
        $this->assertStringNotContainsString('Dodavatel není plátcem DPH', $html);
        $this->assertStringContainsString('Daňový doklad', $html);

        $this->actingInOrg()->get(route('invoices.pdf', $invoice))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_issue_is_refused_when_organization_is_no_longer_vat_payer(): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->marginPayload());
        $invoice = $this->storedInvoice();

        $this->settings->update(['vat_payer' => false]);

        $this->actingInOrg()->post(route('invoices.issue', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('error');

        $invoice->refresh();
        $this->assertSame(IssuedInvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->invoice_number);
        $this->assertSame(VatRegime::UsedGoodsMargin, $invoice->vat_regime);
    }

    public function test_issue_refuses_margin_item_without_acquisition_cost(): void
    {
        app(CurrentOrganization::class)->set($this->org);

        $invoice = IssuedInvoice::factory()->usedGoodsMargin()->draft()->create([
            'organization_id' => $this->org->id,
            'contact_id' => $this->contact->id,
            'number_series_id' => $this->series->id,
            'bank_account_id' => $this->bank->id,
        ]);
        IssuedInvoiceItem::factory()->usedGoods(acquisitionUnitPriceMinor: null)->create([
            'issued_invoice_id' => $invoice->id,
            'organization_id' => $this->org->id,
        ]);

        $this->expectException(InvoiceNotIssuable::class);
        $this->expectExceptionMessage('pořizovací cenu');

        app(IssuedInvoiceLifecycle::class)->issue($invoice);
    }

    public function test_issue_refuses_margin_item_with_fractional_quantity(): void
    {
        app(CurrentOrganization::class)->set($this->org);

        $invoice = IssuedInvoice::factory()->usedGoodsMargin()->draft()->create([
            'organization_id' => $this->org->id,
            'contact_id' => $this->contact->id,
            'number_series_id' => $this->series->id,
            'bank_account_id' => $this->bank->id,
        ]);
        IssuedInvoiceItem::factory()->usedGoods()->create([
            'issued_invoice_id' => $invoice->id,
            'organization_id' => $this->org->id,
            'quantity' => '1.500',
        ]);

        $this->expectException(InvoiceNotIssuable::class);
        $this->expectExceptionMessage('celých kusech');

        app(IssuedInvoiceLifecycle::class)->issue($invoice);
    }

    // ---------- doklad odběratele ----------

    public function test_customer_document_has_statutory_notice_and_no_internal_or_ordinary_vat_figures(): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->marginPayload(['note' => 'Záruka 12 měsíců.']));
        $invoice = $this->storedInvoice();
        $this->actingInOrg()->post(route('invoices.issue', $invoice));

        $data = $this->customerDocument($invoice);
        $html = view('pdf.invoice', ['data' => $data])->render();

        // Povinné a správné údaje.
        $this->assertStringContainsString('zvláštní režim - použité zboží', $html);
        $this->assertStringContainsString('DPH se nevyčísluje.', $html);
        $this->assertStringContainsString('FAKTURA č. FV20260001', $html);
        $this->assertStringContainsString('Daňový doklad', $html);
        $this->assertStringContainsString('IČO: 12345678', $html);
        $this->assertStringContainsString('DIČ: CZ12345678', $html);
        $this->assertStringContainsString('Variabilní symbol', $html);
        $this->assertStringContainsString('20260001', $html);
        $this->assertStringContainsString('DUZP', $html);
        $this->assertStringContainsString('iPhone 13 128 GB, použitý', $html);
        $this->assertStringContainsString('Celkem k úhradě', $html);
        $this->assertStringContainsString("1\u{A0}210,00\u{A0}Kč", $html);
        $this->assertStringContainsString('Záruka 12 měsíců.', $html);

        // Nic z toho nesmí být ve viditelném textu dokladu (CSS/skripty se ignorují,
        // slovo "margin" je běžná CSS vlastnost a není únikem interních údajů).
        $text = $this->visibleText($html);
        foreach ([
            'Rekapitulace DPH',
            'Dodavatel není plátcem DPH',
            'Základ',
            "DPH\u{A0}%",
            'ořizovací',
            'řirážk',
            '36,45',
            '173,55',
            "1\u{A0}000,00",
            'margin',
            'acquisition',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, "Viditelný text dokladu nesmí obsahovat: {$forbidden}");
        }

        // Interní částky a názvy polí nesmí uniknout ani do značek/atributů (mimo <style>/<script>).
        $markup = $this->markupWithoutStyles($html);
        foreach (['36,45', '173,55', "1\u{A0}000,00", '1&nbsp;000,00', 'acquisition', 'margin_vat', 'margin_base'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $markup, "HTML dokladu nesmí obsahovat: {$forbidden}");
        }

        // DTO: částka k úhradě = konečná cena, QR z ní, žádná rekapitulace.
        $this->assertTrue($data->isUsedGoodsMargin());
        $this->assertTrue($data->vatPayer);
        $this->assertFalse($data->showsVatColumns());
        $this->assertSame(121000, $data->total->getMinor());
        $this->assertSame(121000, $data->subtotal->getMinor());
        $this->assertSame([], $data->vatBreakdown);
        $this->assertNull($data->items[0]->vatRate);
        $this->assertSame(0, $data->items[0]->lineVat->getMinor());
        $this->assertNotNull($data->qrDataUri);

        // Strukturálně: DTO ani řádek nenesou interní pole.
        foreach ([InvoicePdfData::class, InvoicePdfLine::class] as $class) {
            foreach (new ReflectionClass($class)->getProperties() as $property) {
                $name = strtolower($property->getName());
                $this->assertStringNotContainsString('acquisition', $name);
                $this->assertStringNotContainsString('margin', $name);
            }
        }
    }

    public function test_admin_show_page_displays_internal_margin_evidence(): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->marginPayload());
        $invoice = $this->storedInvoice();

        $response = $this->actingInOrg()->get(route('invoices.show', $invoice))->assertOk();

        $response->assertSee('Zvláštní režim - použité zboží');
        $response->assertSee('Interní evidence DPH z přirážky');
        $response->assertSee('36,45');
        $response->assertSee('173,55');
        $response->assertSee("1\u{A0}000,00");
        $response->assertDontSee('DPH Kč');
    }

    public function test_dashboard_counts_margin_invoice_by_customer_total(): void
    {
        $this->actingInOrg()->post(route('invoices.store'), $this->marginPayload());
        $invoice = $this->storedInvoice();
        $this->actingInOrg()->post(route('invoices.issue', $invoice));

        $this->actingInOrg()->get(route('dashboard'))
            ->assertOk()
            ->assertSee("1\u{A0}210,00\u{A0}Kč");
    }

    // ---------- regrese běžného režimu ----------

    public function test_standard_vat_payer_invoice_is_unchanged(): void
    {
        $payload = $this->marginPayload(
            ['vat_regime' => VatRegime::Standard->value, 'margin_vat_rate' => null],
            ['acquisition_unit_price' => null, 'unit_price' => '1000', 'vat_rate' => '21', 'quantity' => '2.5'],
        );

        $this->actingInOrg()->post(route('invoices.store'), $payload);
        $invoice = $this->storedInvoice();

        $this->assertSame(VatRegime::Standard, $invoice->vat_regime);
        $this->assertNull($invoice->margin_vat_rate);
        $this->assertSame(250000, $invoice->subtotal_minor);
        $this->assertSame(52500, $invoice->vat_total_minor);
        $this->assertSame(302500, $invoice->total_minor);
        $this->assertSame(0, $invoice->margin_vat_minor);
        $this->assertSame(0, $invoice->margin_base_minor);

        $item = $invoice->items()->firstOrFail();
        $this->assertSame('21.00', (string) $item->vat_rate);
        $this->assertNull($item->acquisition_unit_price_minor);

        $html = view('pdf.invoice', ['data' => $this->customerDocument($invoice)])->render();
        $this->assertStringContainsString('Rekapitulace DPH', $html);
        $this->assertStringNotContainsString('zvláštní režim', $html);
        $this->assertStringNotContainsString('Dodavatel není plátcem DPH', $html);
    }

    public function test_non_vat_payer_invoice_without_regime_field_stays_standard(): void
    {
        $this->settings->update(['vat_payer' => false]);

        $payload = $this->marginPayload(
            ['vat_regime' => null, 'margin_vat_rate' => null, 'tax_date' => null],
            ['acquisition_unit_price' => null],
        );

        $this->actingInOrg()->post(route('invoices.store'), $payload);
        $invoice = $this->storedInvoice();

        $this->assertSame(VatRegime::Standard, $invoice->vat_regime);
        $this->assertNull($invoice->items()->firstOrFail()->vat_rate);
        $this->assertSame(121000, $invoice->total_minor);
        $this->assertSame(0, $invoice->vat_total_minor);
        $this->assertSame(0, $invoice->margin_vat_minor);

        $html = view('pdf.invoice', ['data' => $this->customerDocument($invoice)])->render();
        $this->assertStringContainsString('Dodavatel není plátcem DPH.', $html);
        $this->assertStringNotContainsString('zvláštní režim', $html);
    }

    // ---------- izolace organizací ----------

    public function test_foreign_margin_draft_is_not_reachable(): void
    {
        $orgB = Organization::factory()->create();
        $draftB = IssuedInvoice::factory()->usedGoodsMargin()->draft()->create(['organization_id' => $orgB->id]);

        $this->actingInOrg()->get(route('invoices.show', $draftB))->assertNotFound();
        $this->actingInOrg()->get(route('invoices.edit', $draftB))->assertNotFound();
        $this->actingInOrg()->put(route('invoices.update', $draftB), $this->marginPayload())->assertNotFound();
        $this->actingInOrg()->post(route('invoices.issue', $draftB))->assertNotFound();
        $this->actingInOrg()->get(route('invoices.pdf', $draftB))->assertNotFound();

        $fresh = IssuedInvoice::withoutGlobalScope('organization')->findOrFail($draftB->id);
        $this->assertSame(IssuedInvoiceStatus::Draft, $fresh->status);
        $this->assertSame(VatRegime::UsedGoodsMargin, $fresh->vat_regime);
    }

    public function test_store_rejects_contact_and_series_of_other_organization(): void
    {
        $orgB = Organization::factory()->create();
        $contactB = Contact::factory()->create(['organization_id' => $orgB->id]);
        $seriesB = InvoiceNumberSeries::factory()->create(['organization_id' => $orgB->id]);

        $this->actingInOrg()
            ->post(route('invoices.store'), $this->marginPayload(['contact_id' => $contactB->id, 'number_series_id' => $seriesB->id]))
            ->assertSessionHasErrors(['contact_id', 'number_series_id']);

        $this->assertSame(0, IssuedInvoice::withoutGlobalScope('organization')->count());
    }
}
