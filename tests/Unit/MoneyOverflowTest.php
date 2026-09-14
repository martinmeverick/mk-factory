<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Money\InvoiceTotalsCalculator;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyOverflow;
use PHPUnit\Framework\TestCase;

/**
 * NÁLEZ 7: Money nesmí castovat bcmath výsledek na int bez kontroly rozsahu.
 *
 * Před opravou `(int)` saturoval na PHP_INT_MAX a do databáze se uložilo
 * 9223372036854775807 místo chyby.
 */
class MoneyOverflowTest extends TestCase
{
    /**
     * Původně validovaný extrémní vstup ze zadání review.
     */
    public function test_extreme_price_times_extreme_quantity_is_rejected(): void
    {
        $price = Money::fromDecimalString('9999999999.99');

        $this->expectException(MoneyOverflow::class);

        $price->multiplyBy('999999999.999');
    }

    public function test_overflowing_product_does_not_saturate_to_php_int_max(): void
    {
        $price = Money::fromDecimalString('9999999999.99');
        $saturated = null;

        try {
            $saturated = $price->multiplyBy('999999999.999')->getMinor();
        } catch (MoneyOverflow $e) {
            $this->assertStringContainsString('překračuje podporovaný rozsah', $e->getMessage());
        }

        $this->assertNull($saturated, 'Přetečení se místo chyby uložilo jako '.var_export($saturated, true));
        $this->assertNotSame(PHP_INT_MAX, $saturated);
    }

    public function test_sum_of_individually_valid_amounts_is_rejected_on_overflow(): void
    {
        // Každá hodnota se do rozsahu vejde, jejich součet už ne.
        $half = Money::fromMinor((int) (PHP_INT_MAX / 2) + 10);

        $this->expectException(MoneyOverflow::class);

        $half->plus($half)->plus($half);
    }

    public function test_subtraction_below_range_is_rejected(): void
    {
        $lowest = Money::fromMinor(PHP_INT_MIN + 5);

        $this->expectException(MoneyOverflow::class);

        $lowest->minus(Money::fromMinor(1000));
    }

    public function test_vat_of_an_overflowing_base_is_rejected(): void
    {
        $huge = Money::fromMinor(PHP_INT_MAX);

        $this->expectException(MoneyOverflow::class);

        // 121 % z maxima se do rozsahu nevejde.
        $huge->percentage('121');
    }

    public function test_decimal_string_beyond_range_is_rejected(): void
    {
        $this->expectException(MoneyOverflow::class);

        Money::fromDecimalString('99999999999999999999.99');
    }

    public function test_line_calculation_rejects_overflow_instead_of_storing_garbage(): void
    {
        $calculator = new InvoiceTotalsCalculator;

        $this->expectException(MoneyOverflow::class);

        $calculator->calculateLine(
            '999999999.999',
            Money::fromDecimalString('9999999999.99'),
            '21',
        );
    }

    public function test_values_at_the_boundary_are_still_accepted(): void
    {
        $max = Money::fromMinor(PHP_INT_MAX);

        $this->assertSame(PHP_INT_MAX, $max->getMinor());
        $this->assertSame(PHP_INT_MAX, $max->plus(Money::zero())->getMinor());
    }

    public function test_ordinary_amounts_are_unaffected(): void
    {
        $price = Money::fromDecimalString('1200.00');

        $this->assertSame(300000, $price->multiplyBy('2.5')->getMinor());
        $this->assertSame(63000, Money::fromDecimalString('3000.00')->percentage('21')->getMinor());
        $this->assertSame(350, Money::fromMinor(100)->plus(Money::fromMinor(250))->getMinor());
        $this->assertSame(-150, Money::fromMinor(100)->minus(Money::fromMinor(250))->getMinor());
    }
}
