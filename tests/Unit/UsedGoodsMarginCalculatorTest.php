<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Money\InvoiceTotalsCalculator;
use App\Domain\Money\Money;
use App\Enums\VatRegime;
use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Zvláštní režim - použité zboží (§ 90 ZDPH): výpočet přirážky a DPH
 * z přirážky. Prodejní cena je konečná (vč. DPH), běžná DPH řádku je 0,
 * DPH z přirážky = přirážka × 21/121 half-up na haléře po řádcích.
 */
class UsedGoodsMarginCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceTotalsCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new InvoiceTotalsCalculator();
    }

    /**
     * @param  list<array<string, mixed>>  $items  přepisy atributů položek (usedGoods stav)
     */
    private function makeMarginInvoice(array $items, array $invoiceOverrides = []): IssuedInvoice
    {
        $invoice = IssuedInvoice::factory()->usedGoodsMargin()->create($invoiceOverrides);

        foreach ($items as $index => $item) {
            IssuedInvoiceItem::factory()->usedGoods()->create([
                'issued_invoice_id' => $invoice->id,
                'organization_id' => $invoice->organization_id,
                'position' => $index + 1,
                ...$item,
            ]);
        }

        return $invoice;
    }

    public function test_example_from_specification_1210_sale_1000_acquisition(): void
    {
        $invoice = $this->makeMarginInvoice([
            ['unit_price_minor' => 121000, 'acquisition_unit_price_minor' => 100000],
        ]);

        $this->calculator->recalculate($invoice);
        $invoice->refresh();

        // Doklad odběratele: 1 210 Kč, žádná běžná DPH.
        $this->assertSame(121000, $invoice->subtotal_minor);
        $this->assertSame(0, $invoice->vat_total_minor);
        $this->assertSame(121000, $invoice->total_minor);

        // Interní evidence: přirážka 210, DPH 36,45, základ 173,55.
        $this->assertSame(100000, $invoice->margin_acquisition_total_minor);
        $this->assertSame(21000, $invoice->margin_gross_minor);
        $this->assertSame(3645, $invoice->margin_vat_minor);
        $this->assertSame(17355, $invoice->margin_base_minor);

        $item = $invoice->items()->firstOrFail();
        $this->assertSame(121000, $item->line_subtotal_minor);
        $this->assertSame(0, $item->line_vat_minor);
        $this->assertSame(121000, $item->line_total_minor);
        $this->assertSame(100000, $item->line_acquisition_minor);
        $this->assertSame(21000, $item->line_margin_gross_minor);
        $this->assertSame(3645, $item->line_margin_vat_minor);
        $this->assertSame(17355, $item->line_margin_base_minor);
    }

    public function test_quantity_multiplies_selling_and_acquisition_before_margin(): void
    {
        $invoice = $this->makeMarginInvoice([
            ['quantity' => '2.000', 'unit_price_minor' => 121000, 'acquisition_unit_price_minor' => 100000],
        ]);

        $this->calculator->recalculate($invoice);
        $invoice->refresh();

        $this->assertSame(242000, $invoice->total_minor);
        $this->assertSame(200000, $invoice->margin_acquisition_total_minor);
        $this->assertSame(42000, $invoice->margin_gross_minor);
        // 420 × 21/121 = 72,892… → 72,89
        $this->assertSame(7289, $invoice->margin_vat_minor);
        $this->assertSame(34711, $invoice->margin_base_minor);
    }

    public function test_zero_margin_yields_zero_internal_tax(): void
    {
        $invoice = $this->makeMarginInvoice([
            ['unit_price_minor' => 100000, 'acquisition_unit_price_minor' => 100000],
        ]);

        $this->calculator->recalculate($invoice);
        $invoice->refresh();

        $this->assertSame(100000, $invoice->total_minor);
        $this->assertSame(0, $invoice->margin_gross_minor);
        $this->assertSame(0, $invoice->margin_vat_minor);
        $this->assertSame(0, $invoice->margin_base_minor);
    }

    public function test_negative_margin_keeps_selling_price_and_zeroes_internal_tax(): void
    {
        $invoice = $this->makeMarginInvoice([
            ['unit_price_minor' => 121000, 'acquisition_unit_price_minor' => 150000],
        ]);

        $this->calculator->recalculate($invoice);
        $invoice->refresh();

        // Částka k úhradě zůstává 1 210 Kč.
        $this->assertSame(121000, $invoice->subtotal_minor);
        $this->assertSame(121000, $invoice->total_minor);
        $this->assertSame(0, $invoice->vat_total_minor);

        // Interně: pořízení 1 500, přirážka/DPH/základ 0 (nikdy záporné).
        $this->assertSame(150000, $invoice->margin_acquisition_total_minor);
        $this->assertSame(0, $invoice->margin_gross_minor);
        $this->assertSame(0, $invoice->margin_vat_minor);
        $this->assertSame(0, $invoice->margin_base_minor);

        $item = $invoice->items()->firstOrFail();
        $this->assertSame(0, $item->line_margin_gross_minor);
        $this->assertSame(0, $item->line_margin_vat_minor);
        $this->assertSame(0, $item->line_margin_base_minor);
    }

    public function test_margin_is_floored_per_line_not_netted_across_lines(): void
    {
        $invoice = $this->makeMarginInvoice([
            ['unit_price_minor' => 121000, 'acquisition_unit_price_minor' => 100000], // +210
            ['unit_price_minor' => 50000, 'acquisition_unit_price_minor' => 80000],   // −300 → 0
        ]);

        $this->calculator->recalculate($invoice);
        $invoice->refresh();

        $this->assertSame(171000, $invoice->total_minor);
        $this->assertSame(180000, $invoice->margin_acquisition_total_minor);
        $this->assertSame(21000, $invoice->margin_gross_minor);
        $this->assertSame(3645, $invoice->margin_vat_minor);
        $this->assertSame(17355, $invoice->margin_base_minor);
    }

    public function test_margin_vat_rounds_half_up_per_line_without_floats(): void
    {
        $invoice = $this->makeMarginInvoice([
            // přirážka 1,00 Kč → 100 × 21/121 = 17,355… → 17 hal; základ 83
            ['unit_price_minor' => 101, 'acquisition_unit_price_minor' => 1],
            // přirážka 0,05 Kč → 5 × 21/121 = 0,867… → 1 hal; základ 4
            ['unit_price_minor' => 6, 'acquisition_unit_price_minor' => 1],
        ]);

        $this->calculator->recalculate($invoice);
        $invoice->refresh();

        $items = $invoice->items()->get();
        $this->assertSame([17, 1], $items->pluck('line_margin_vat_minor')->all());
        $this->assertSame([83, 4], $items->pluck('line_margin_base_minor')->all());
        $this->assertSame(18, $invoice->margin_vat_minor);
        $this->assertSame(87, $invoice->margin_base_minor);
        $this->assertSame(105, $invoice->margin_gross_minor);
    }

    public function test_margin_lines_carry_no_ordinary_vat_rate_and_breakdown_is_empty(): void
    {
        // I kdyby položka měla omylem sazbu, ve zvláštním režimu se vynuluje.
        $invoice = $this->makeMarginInvoice([
            ['unit_price_minor' => 121000, 'acquisition_unit_price_minor' => 100000, 'vat_rate' => '21.00'],
        ]);

        $this->calculator->recalculate($invoice);
        $invoice->refresh();

        $this->assertNull($invoice->items()->firstOrFail()->vat_rate);
        $this->assertSame(0, $invoice->vat_total_minor);
        $this->assertSame([], $this->calculator->vatBreakdown($invoice));
    }

    public function test_missing_acquisition_cost_throws_instead_of_silent_zero_tax(): void
    {
        $invoice = $this->makeMarginInvoice([
            ['unit_price_minor' => 121000, 'acquisition_unit_price_minor' => null],
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->calculator->recalculate($invoice);
    }

    public function test_missing_or_unsupported_margin_rate_throws(): void
    {
        $invoice = $this->makeMarginInvoice(
            [['unit_price_minor' => 121000, 'acquisition_unit_price_minor' => 100000]],
            ['margin_vat_rate' => null],
        );

        $this->expectException(InvalidArgumentException::class);

        $this->calculator->recalculate($invoice);
    }

    public function test_standard_regime_ignores_acquisition_and_keeps_ordinary_vat_math(): void
    {
        $invoice = IssuedInvoice::factory()->create([
            'vat_regime' => VatRegime::Standard,
            'subtotal_minor' => 0, 'vat_total_minor' => 0, 'total_minor' => 0,
            'margin_gross_minor' => 999, 'margin_vat_minor' => 999, 'margin_base_minor' => 999,
        ]);
        IssuedInvoiceItem::factory()->create([
            'issued_invoice_id' => $invoice->id,
            'organization_id' => $invoice->organization_id,
            'quantity' => '1.000',
            'unit_price_minor' => 100000,
            'vat_rate' => '21.00',
            // Stará/cizí hodnota — v běžném režimu nesmí nic ovlivnit.
            'acquisition_unit_price_minor' => 50000,
            'line_margin_vat_minor' => 999,
        ]);

        $this->calculator->recalculate($invoice);
        $invoice->refresh();

        $this->assertSame(100000, $invoice->subtotal_minor);
        $this->assertSame(21000, $invoice->vat_total_minor);
        $this->assertSame(121000, $invoice->total_minor);
        $this->assertSame(0, $invoice->margin_acquisition_total_minor);
        $this->assertSame(0, $invoice->margin_gross_minor);
        $this->assertSame(0, $invoice->margin_vat_minor);
        $this->assertSame(0, $invoice->margin_base_minor);

        $item = $invoice->items()->firstOrFail();
        $this->assertSame('21.00', (string) $item->vat_rate);
        $this->assertSame(21000, $item->line_vat_minor);
        $this->assertSame(0, $item->line_acquisition_minor);
        $this->assertSame(0, $item->line_margin_vat_minor);
    }

    public function test_calculate_margin_line_is_pure(): void
    {
        $line = $this->calculator->calculateMarginLine(
            '1.000',
            Money::fromMinor(121000, 'CZK'),
            Money::fromMinor(100000, 'CZK'),
            '21.00',
        );

        $this->assertSame(121000, $line['subtotal']->getMinor());
        $this->assertSame(0, $line['vat']->getMinor());
        $this->assertSame(121000, $line['total']->getMinor());
        $this->assertSame(100000, $line['acquisition']->getMinor());
        $this->assertSame(21000, $line['margin_gross']->getMinor());
        $this->assertSame(3645, $line['margin_vat']->getMinor());
        $this->assertSame(17355, $line['margin_base']->getMinor());

        $loss = $this->calculator->calculateMarginLine(
            '3.000',
            Money::fromMinor(10000, 'CZK'),
            Money::fromMinor(20000, 'CZK'),
            '21.00',
        );

        $this->assertSame(30000, $loss['total']->getMinor());
        $this->assertSame(60000, $loss['acquisition']->getMinor());
        $this->assertSame(0, $loss['margin_gross']->getMinor());
        $this->assertSame(0, $loss['margin_vat']->getMinor());
        $this->assertSame(0, $loss['margin_base']->getMinor());
    }
}
