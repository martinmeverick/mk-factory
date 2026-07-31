<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Money\InvoiceTotalsCalculator;
use App\Domain\Money\Money;
use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTotalsCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceTotalsCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new InvoiceTotalsCalculator();
    }

    /**
     * @param list<array{quantity: string, unit_price_minor: int, vat_rate: ?string}> $items
     */
    private function makeInvoice(array $items): IssuedInvoice
    {
        $invoice = IssuedInvoice::factory()->create([
            'subtotal_minor' => 0,
            'vat_total_minor' => 0,
            'total_minor' => 0,
        ]);

        foreach ($items as $index => $item) {
            IssuedInvoiceItem::factory()->create([
                'issued_invoice_id' => $invoice->id,
                'organization_id' => $invoice->organization_id,
                'position' => $index + 1,
                ...$item,
            ]);
        }

        return $invoice;
    }

    public function test_vat_payer_rates_21_12_0(): void
    {
        $invoice = $this->makeInvoice([
            ['quantity' => '1.000', 'unit_price_minor' => 10000, 'vat_rate' => '21.00'],
            ['quantity' => '1.000', 'unit_price_minor' => 10000, 'vat_rate' => '12.00'],
            ['quantity' => '1.000', 'unit_price_minor' => 10000, 'vat_rate' => '0.00'],
        ]);

        $this->calculator->recalculate($invoice);
        $invoice->refresh();

        $items = $invoice->items()->get();
        $this->assertSame([10000, 10000, 10000], $items->pluck('line_subtotal_minor')->all());
        $this->assertSame([2100, 1200, 0], $items->pluck('line_vat_minor')->all());
        $this->assertSame([12100, 11200, 10000], $items->pluck('line_total_minor')->all());

        $this->assertSame(30000, $invoice->subtotal_minor);
        $this->assertSame(3300, $invoice->vat_total_minor);
        $this->assertSame(33300, $invoice->total_minor);
    }

    public function test_mixed_rates_vat_breakdown_grouped_by_rate(): void
    {
        $invoice = $this->makeInvoice([
            ['quantity' => '1.000', 'unit_price_minor' => 10000, 'vat_rate' => '21.00'],
            ['quantity' => '2.000', 'unit_price_minor' => 5000, 'vat_rate' => '21.00'],
            ['quantity' => '1.000', 'unit_price_minor' => 20000, 'vat_rate' => '12.00'],
        ]);

        $this->calculator->recalculate($invoice);

        $breakdown = $this->calculator->vatBreakdown($invoice);

        $this->assertSame(['21.00', '12.00'], array_keys($breakdown));
        $this->assertSame(20000, $breakdown['21.00']['base']->getMinor());
        $this->assertSame(4200, $breakdown['21.00']['vat']->getMinor());
        $this->assertSame(24200, $breakdown['21.00']['total']->getMinor());
        $this->assertSame(20000, $breakdown['12.00']['base']->getMinor());
        $this->assertSame(2400, $breakdown['12.00']['vat']->getMinor());
    }

    public function test_rounding_is_applied_per_line(): void
    {
        // 2× položka za 0,55 Kč s 21 %: DPH řádku 11.55 → 12 hal, součet 24.
        // (Při součtu základů by vyšlo round(110 × 0.21) = 23 — musí být 24.)
        $invoice = $this->makeInvoice([
            ['quantity' => '1.000', 'unit_price_minor' => 55, 'vat_rate' => '21.00'],
            ['quantity' => '1.000', 'unit_price_minor' => 55, 'vat_rate' => '21.00'],
        ]);

        $this->calculator->recalculate($invoice);
        $invoice->refresh();

        $this->assertSame([12, 12], $invoice->items()->get()->pluck('line_vat_minor')->all());
        $this->assertSame(24, $invoice->vat_total_minor);
        $this->assertSame(134, $invoice->total_minor);
    }

    public function test_non_vat_payer_null_rate_means_zero_vat(): void
    {
        $invoice = $this->makeInvoice([
            ['quantity' => '3.000', 'unit_price_minor' => 12345, 'vat_rate' => null],
            ['quantity' => '1.000', 'unit_price_minor' => 5000, 'vat_rate' => null],
        ]);

        $this->calculator->recalculate($invoice);
        $invoice->refresh();

        $this->assertSame(42035, $invoice->subtotal_minor);
        $this->assertSame(0, $invoice->vat_total_minor);
        $this->assertSame(42035, $invoice->total_minor);
        $this->assertSame([], $this->calculator->vatBreakdown($invoice));
    }

    public function test_decimal_quantity_rounds_half_up_per_line(): void
    {
        // 2.500 × 3.33 Kč = 8.325 Kč → 833 hal; DPH 21 %: 174.93 → 175 hal.
        $invoice = $this->makeInvoice([
            ['quantity' => '2.500', 'unit_price_minor' => 333, 'vat_rate' => '21.00'],
        ]);

        $this->calculator->recalculate($invoice);
        $invoice->refresh();

        $item = $invoice->items()->first();
        $this->assertSame(833, $item->line_subtotal_minor);
        $this->assertSame(175, $item->line_vat_minor);
        $this->assertSame(1008, $item->line_total_minor);
        $this->assertSame(1008, $invoice->total_minor);
    }

    public function test_calculate_line_is_pure(): void
    {
        $line = $this->calculator->calculateLine('2.000', Money::fromMinor(50000, 'CZK'), '21.00');

        $this->assertSame(100000, $line['subtotal']->getMinor());
        $this->assertSame(21000, $line['vat']->getMinor());
        $this->assertSame(121000, $line['total']->getMinor());

        $noVat = $this->calculator->calculateLine('1.000', Money::fromMinor(10000, 'CZK'), null);
        $this->assertSame(0, $noVat['vat']->getMinor());
        $this->assertSame(10000, $noVat['total']->getMinor());
    }
}
