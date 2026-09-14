<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_from_minor_and_getters(): void
    {
        $money = Money::fromMinor(123456, 'czk');

        $this->assertSame(123456, $money->getMinor());
        $this->assertSame('CZK', $money->getCurrency());
    }

    public function test_from_decimal_string_with_dot(): void
    {
        $this->assertSame(123456, Money::fromDecimalString('1234.56', 'CZK')->getMinor());
        $this->assertSame(120000, Money::fromDecimalString('1200', 'CZK')->getMinor());
        $this->assertSame(150, Money::fromDecimalString('1.5', 'CZK')->getMinor());
    }

    public function test_from_decimal_string_with_comma_and_spaces(): void
    {
        $this->assertSame(123456, Money::fromDecimalString('1 234,56', 'CZK')->getMinor());
        $this->assertSame(-550, Money::fromDecimalString('-5,50', 'CZK')->getMinor());
    }

    public function test_from_decimal_string_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimalString('abc');
    }

    public function test_from_decimal_string_rejects_three_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimalString('1.234');
    }

    public function test_plus_and_minus(): void
    {
        $a = Money::fromMinor(1000, 'CZK');
        $b = Money::fromMinor(250, 'CZK');

        $this->assertSame(1250, $a->plus($b)->getMinor());
        $this->assertSame(750, $a->minus($b)->getMinor());
        // Immutable — původní instance beze změny.
        $this->assertSame(1000, $a->getMinor());
    }

    public function test_multiply_by_rounds_half_up(): void
    {
        // 333 × 2.5 = 832.5 → 833
        $this->assertSame(833, Money::fromMinor(333, 'CZK')->multiplyBy('2.5')->getMinor());
        // 100 × 0.005 = 0.5 → 1
        $this->assertSame(1, Money::fromMinor(100, 'CZK')->multiplyBy('0.005')->getMinor());
        // Celé množství bez zaokrouhlení.
        $this->assertSame(999, Money::fromMinor(333, 'CZK')->multiplyBy('3')->getMinor());
        // Čárka jako oddělovač.
        $this->assertSame(833, Money::fromMinor(333, 'CZK')->multiplyBy('2,5')->getMinor());
    }

    public function test_multiply_by_rejects_invalid_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(100, 'CZK')->multiplyBy('x');
    }

    public function test_percentage_rounds_half_up(): void
    {
        $this->assertSame(2100, Money::fromMinor(10000, 'CZK')->percentage('21')->getMinor());
        // 105 × 10 % = 10.5 → 11
        $this->assertSame(11, Money::fromMinor(105, 'CZK')->percentage('10')->getMinor());
        // 999 × 21 % = 209.79 → 210
        $this->assertSame(210, Money::fromMinor(999, 'CZK')->percentage('21.00')->getMinor());
        $this->assertSame(0, Money::fromMinor(10000, 'CZK')->percentage('0')->getMinor());
    }

    public function test_included_vat_at_rate_rounds_half_up(): void
    {
        // Přirážka 210,00 Kč při 21 %: 21000 × 21/121 = 3644,628… → 3645 (36,45 Kč)
        $this->assertSame(3645, Money::fromMinor(21000, 'CZK')->includedVatAtRate('21.00')->getMinor());
        $this->assertSame(3645, Money::fromMinor(21000, 'CZK')->includedVatAtRate('21')->getMinor());
        // 1,00 Kč → 17,355… → 17
        $this->assertSame(17, Money::fromMinor(100, 'CZK')->includedVatAtRate('21')->getMinor());
        // 0,05 Kč → 0,867… → 1
        $this->assertSame(1, Money::fromMinor(5, 'CZK')->includedVatAtRate('21')->getMinor());
        // 0,02 Kč → 0,347… → 0
        $this->assertSame(0, Money::fromMinor(2, 'CZK')->includedVatAtRate('21')->getMinor());
        $this->assertSame(0, Money::zero()->includedVatAtRate('21')->getMinor());
        // 1 210,00 Kč vč. 21 % → DPH 210,00 Kč (koeficient přesně)
        $this->assertSame(21000, Money::fromMinor(121000, 'CZK')->includedVatAtRate('21')->getMinor());
        // Jiná sazba: 112,00 vč. 12 % → 12,00
        $this->assertSame(1200, Money::fromMinor(11200, 'CZK')->includedVatAtRate('12')->getMinor());
    }

    public function test_included_vat_at_rate_rejects_invalid_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(100, 'CZK')->includedVatAtRate('21 %');
    }

    public function test_percentage_rejects_invalid_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(100, 'CZK')->percentage('21%');
    }

    public function test_mixing_currencies_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(100, 'CZK')->plus(Money::fromMinor(100, 'EUR'));
    }

    public function test_mixing_currencies_in_comparison_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(100, 'CZK')->greaterThanOrEqual(Money::fromMinor(1, 'EUR'));
    }

    public function test_format_czech(): void
    {
        $this->assertSame("1\u{A0}234,56\u{A0}Kč", Money::fromMinor(123456, 'CZK')->formatCzech());
        $this->assertSame("0,05\u{A0}Kč", Money::fromMinor(5, 'CZK')->formatCzech());
        $this->assertSame("−123,45\u{A0}Kč", Money::fromMinor(-12345, 'CZK')->formatCzech());
        $this->assertSame("5,00\u{A0}EUR", Money::fromMinor(500, 'EUR')->formatCzech());
    }

    public function test_to_decimal_string(): void
    {
        $this->assertSame('1234.50', Money::fromMinor(123450, 'CZK')->toDecimalString());
        $this->assertSame('0.05', Money::fromMinor(5, 'CZK')->toDecimalString());
        $this->assertSame('-0.50', Money::fromMinor(-50, 'CZK')->toDecimalString());
        $this->assertSame('0.00', Money::zero()->toDecimalString());
    }

    public function test_comparison_helpers(): void
    {
        $this->assertTrue(Money::zero()->isZero());
        $this->assertTrue(Money::fromMinor(-1, 'CZK')->isNegative());
        $this->assertTrue(Money::fromMinor(1, 'CZK')->isPositive());
        $this->assertTrue(
            Money::fromMinor(100, 'CZK')->greaterThanOrEqual(Money::fromMinor(100, 'CZK'))
        );
        $this->assertFalse(
            Money::fromMinor(99, 'CZK')->greaterThanOrEqual(Money::fromMinor(100, 'CZK'))
        );
    }
}
