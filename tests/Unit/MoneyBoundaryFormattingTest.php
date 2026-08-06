<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Money\Money;
use App\Domain\Money\MoneyOverflow;
use PHPUnit\Framework\TestCase;

/**
 * RE-REVIEW, okraj PHP_INT_MIN: formátování krajních hodnot rozsahu.
 *
 * `abs(PHP_INT_MIN)` se nevejde do int a přetéká do floatu — dřívější
 * implementace `toDecimalString()`/`formatCzech()` proto na nejnižší
 * hodnotě padala na TypeError (`intdiv()` dostal float). Formátování teď
 * jede čistě přes řetězce; tyto testy hlídají obě hranice, hodnoty o jedna
 * uvnitř i mimo rozsah a nepřítomnost floatu/scientific notation.
 */
class MoneyBoundaryFormattingTest extends TestCase
{
    private const string NBSP = "\u{A0}";

    public function test_php_int_min_formats_exactly(): void
    {
        $money = Money::fromMinor(PHP_INT_MIN);

        $this->assertSame('-92233720368547758.08', $money->toDecimalString());
        $this->assertSame(
            '−92'.self::NBSP.'233'.self::NBSP.'720'.self::NBSP.'368'.self::NBSP
                .'547'.self::NBSP.'758,08'.self::NBSP.'Kč',
            $money->formatCzech(),
        );
    }

    public function test_php_int_max_formats_exactly(): void
    {
        $money = Money::fromMinor(PHP_INT_MAX);

        $this->assertSame('92233720368547758.07', $money->toDecimalString());
        $this->assertSame(
            '92'.self::NBSP.'233'.self::NBSP.'720'.self::NBSP.'368'.self::NBSP
                .'547'.self::NBSP.'758,07'.self::NBSP.'Kč',
            $money->formatCzech(),
        );
    }

    public function test_values_one_inside_the_boundaries_format_exactly(): void
    {
        $this->assertSame('-92233720368547758.07', Money::fromMinor(PHP_INT_MIN + 1)->toDecimalString());
        $this->assertSame('92233720368547758.06', Money::fromMinor(PHP_INT_MAX - 1)->toDecimalString());
    }

    public function test_value_one_above_the_range_is_rejected(): void
    {
        $this->expectException(MoneyOverflow::class);

        // PHP_INT_MAX v haléřích je 92233720368547758.07 — o haléř víc přeteče.
        Money::fromDecimalString('92233720368547758.08');
    }

    public function test_value_one_below_the_range_is_rejected(): void
    {
        $this->expectException(MoneyOverflow::class);

        Money::fromDecimalString('-92233720368547758.09');
    }

    public function test_arithmetic_one_step_beyond_each_boundary_is_rejected(): void
    {
        try {
            Money::fromMinor(PHP_INT_MAX)->plus(Money::fromMinor(1));
            $this->fail('PHP_INT_MAX + 1 haléř musí skončit MoneyOverflow.');
        } catch (MoneyOverflow) {
            // očekáváno
        }

        $this->expectException(MoneyOverflow::class);

        Money::fromMinor(PHP_INT_MIN)->minus(Money::fromMinor(1));
    }

    public function test_boundaries_round_trip_through_decimal_string(): void
    {
        $this->assertSame(PHP_INT_MIN, Money::fromDecimalString('-92233720368547758.08')->getMinor());
        $this->assertSame(PHP_INT_MAX, Money::fromDecimalString('92233720368547758.07')->getMinor());
    }

    public function test_no_float_or_scientific_notation_leaks_into_output(): void
    {
        foreach ([PHP_INT_MIN, PHP_INT_MIN + 1, -1, 0, 1, PHP_INT_MAX - 1, PHP_INT_MAX] as $minor) {
            $decimal = Money::fromMinor($minor)->toDecimalString();
            $czech = Money::fromMinor($minor)->formatCzech();

            $this->assertMatchesRegularExpression(
                '/^-?\d+\.\d{2}$/',
                $decimal,
                "toDecimalString({$minor}) musí být čistě číselný zápis.",
            );

            foreach (['E', 'e', 'INF', 'NAN'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $decimal);
                $this->assertStringNotContainsString($forbidden, $czech);
            }
        }
    }

    public function test_ordinary_amounts_format_unchanged(): void
    {
        $this->assertSame('0.00', Money::fromMinor(0)->toDecimalString());
        $this->assertSame('-0.05', Money::fromMinor(-5)->toDecimalString());
        $this->assertSame('1234.56', Money::fromMinor(123456)->toDecimalString());
        $this->assertSame('0,00'.self::NBSP.'Kč', Money::fromMinor(0)->formatCzech());
        $this->assertSame('1'.self::NBSP.'234,56'.self::NBSP.'Kč', Money::fromMinor(123456)->formatCzech());
        $this->assertSame('−1'.self::NBSP.'234,56'.self::NBSP.'Kč', Money::fromMinor(-123456)->formatCzech());
        $this->assertSame('999,99'.self::NBSP.'EUR', Money::fromMinor(99999, 'EUR')->formatCzech());
    }
}
