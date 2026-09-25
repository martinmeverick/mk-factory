<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Domain\Invoicing\IssuedInvoiceLifecycle;
use App\Domain\Money\InvoiceTotalsCalculator;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\VatRegime;
use App\Models\Contact;
use App\Models\InvoiceNumberSeries;
use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use App\Models\Organization;
use App\Models\OrganizationSettings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDiscountFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function draft(array $prices, bool $vat = true, bool $margin = false): IssuedInvoice
    {
        $org = Organization::factory()->create();
        app(CurrentOrganization::class)->set($org);
        OrganizationSettings::factory()->create(['organization_id' => $org->id, 'vat_payer' => $vat]);
        $invoice = IssuedInvoice::factory()->create([
            'organization_id' => $org->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $org->id])->id,
            'number_series_id' => InvoiceNumberSeries::factory()->create(['organization_id' => $org->id])->id,
            'bank_account_id' => null,
            'vat_regime' => $margin ? VatRegime::UsedGoodsMargin : VatRegime::Standard,
            'margin_vat_rate' => $margin ? '21.00' : null,
            'discount_type' => 'fixed', 'discount_value' => '2000',
        ]);
        foreach ($prices as $index => [$price, $rate]) {
            IssuedInvoiceItem::factory()->create([
                'organization_id' => $org->id, 'issued_invoice_id' => $invoice->id,
                'position' => $index + 1, 'quantity' => '1', 'unit_price_minor' => $price,
                'vat_rate' => $vat && ! $margin ? $rate : null,
                'acquisition_unit_price_minor' => $margin ? 900000 : null,
            ]);
        }

        return $invoice;
    }

    public function test_mixed_rates_discount_matches_header_lines_vat_and_is_idempotent(): void
    {
        $invoice = $this->draft([[1000000, '21'], [1000000, '12']]);
        $calculator = app(InvoiceTotalsCalculator::class);
        $calculator->recalculate($invoice);
        $this->assertSame(2130000, $invoice->total_minor);
        $this->assertSame(200000, $invoice->discount_total_minor);
        $items = $invoice->items()->get();
        $this->assertSame(2130000, $items->sum('line_total_minor'));
        $this->assertSame(200000, $items->sum('line_discount_minor'));
        $this->assertSame($invoice->total_minor, $invoice->subtotal_minor + $invoice->vat_total_minor);
        $this->assertSame($invoice->vat_total_minor, array_sum(array_map(fn ($r) => $r['vat']->getMinor(), $calculator->vatBreakdown($invoice))));
        $snapshot = $items->pluck('line_total_minor')->all();
        $calculator->recalculate($invoice);
        $this->assertSame($snapshot, $invoice->items()->get()->pluck('line_total_minor')->all());
    }

    public function test_nonpayer_and_margin_keep_the_correct_tax_semantics(): void
    {
        $invoice = $this->draft([[1000000, null]], false);
        app(InvoiceTotalsCalculator::class)->recalculate($invoice);
        $this->assertSame(800000, $invoice->total_minor);
        $this->assertSame(0, $invoice->vat_total_minor);
        app(CurrentOrganization::class)->set(null);
        $margin = $this->draft([[1210000, null]], true, true);
        app(InvoiceTotalsCalculator::class)->recalculate($margin);
        $this->assertSame(1010000, $margin->total_minor);
        $this->assertSame(0, $margin->vat_total_minor);
        $this->assertSame(900000, $margin->margin_acquisition_total_minor);
        $this->assertSame(110000, $margin->margin_gross_minor);
        $this->assertSame(19091, $margin->margin_vat_minor);
    }

    public function test_issue_freezes_discount_and_payments_use_discounted_total(): void
    {
        $invoice = $this->draft([[1000000, null]], false);
        $lifecycle = app(IssuedInvoiceLifecycle::class);
        $lifecycle->issue($invoice);
        $invoice->refresh();
        $this->assertSame(800000, $invoice->total_minor);
        $lifecycle->markPaid($invoice, CarbonImmutable::now());
        $this->assertSame(800000, $invoice->refresh()->paid_amount_minor);
        $this->expectException(ImmutableInvoiceViolation::class);
        $invoice->update(['discount_value' => '1000']);
    }

    public function test_http_invalid_discount_rolls_back_and_decimal_comma_is_accepted(): void
    {
        $invoice = $this->draft([[1000000, null]], false);
        $user = User::factory()->create();
        $user->organizations()->attach($invoice->organization_id, ['role' => 'owner']);
        $this->actingAs($user)->withSession(['current_organization_id' => $invoice->organization_id]);
        $payload = [
            'contact_id' => $invoice->contact_id, 'number_series_id' => $invoice->number_series_id,
            'issue_date' => '2026-09-25', 'due_date' => '2026-10-09',
            'discount_type' => 'fixed', 'discount_value' => '2000,00',
            'items' => [['description' => 'Služba', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '10000']],
        ];
        $this->post(route('invoices.store'), $payload)->assertSessionHasNoErrors();
        $saved = IssuedInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame(800000, $saved->total_minor);
        $count = IssuedInvoice::query()->count();
        $this->post(route('invoices.store'), array_replace($payload, ['discount_value' => '10000.01']))->assertSessionHasErrors('discount_value');
        $this->assertSame($count, IssuedInvoice::query()->count());
        $this->put(route('invoices.update', $saved), array_replace($payload, ['discount_type' => 'percent', 'discount_value' => '10']))->assertSessionHasNoErrors();
        $this->assertSame(900000, $saved->refresh()->total_minor);
        $this->post(route('invoices.store'), array_replace($payload, ['discount_type' => 'percent', 'discount_value' => '101']))->assertSessionHasErrors('discount_value');
    }
}
