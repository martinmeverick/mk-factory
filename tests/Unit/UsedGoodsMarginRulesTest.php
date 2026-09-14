<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Money\UsedGoodsMargin;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UsedGoodsMarginRulesTest extends TestCase
{
    public function test_customer_notice_is_exact_statutory_text(): void
    {
        $this->assertSame('zvláštní režim - použité zboží', UsedGoodsMargin::CUSTOMER_NOTICE);
        $this->assertSame('DPH se nevyčísluje.', UsedGoodsMargin::CUSTOMER_NOTICE_SUPPLEMENT);
    }

    public function test_only_21_percent_is_supported_in_first_version(): void
    {
        $this->assertSame(['21.00'], UsedGoodsMargin::SUPPORTED_VAT_RATES);
        $this->assertSame(['21'], UsedGoodsMargin::rateOptions());
        $this->assertSame('21', UsedGoodsMargin::rateToOption('21.00'));
    }

    public function test_normalize_rate_accepts_supported_input_forms(): void
    {
        $this->assertSame('21.00', UsedGoodsMargin::normalizeRate('21'));
        $this->assertSame('21.00', UsedGoodsMargin::normalizeRate('21,00'));
        $this->assertSame('21.00', UsedGoodsMargin::normalizeRate('21.00'));
        $this->assertSame('21.00', UsedGoodsMargin::normalizeRate(' 21.0 '));
    }

    public function test_normalize_rate_rejects_unsupported_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UsedGoodsMargin::normalizeRate('12');
    }

    public function test_normalize_rate_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UsedGoodsMargin::normalizeRate('21%');
    }

    public function test_is_supported_rate(): void
    {
        $this->assertTrue(UsedGoodsMargin::isSupportedRate('21.00'));
        $this->assertTrue(UsedGoodsMargin::isSupportedRate('21'));
        $this->assertFalse(UsedGoodsMargin::isSupportedRate('12.00'));
        $this->assertFalse(UsedGoodsMargin::isSupportedRate('0'));
        $this->assertFalse(UsedGoodsMargin::isSupportedRate(null));
        $this->assertFalse(UsedGoodsMargin::isSupportedRate(''));
    }

    public function test_whole_positive_quantity_rule(): void
    {
        $this->assertTrue(UsedGoodsMargin::isWholePositiveQuantity('1'));
        $this->assertTrue(UsedGoodsMargin::isWholePositiveQuantity('2.000'));
        $this->assertTrue(UsedGoodsMargin::isWholePositiveQuantity('3,000'));
        $this->assertTrue(UsedGoodsMargin::isWholePositiveQuantity('150'));

        $this->assertFalse(UsedGoodsMargin::isWholePositiveQuantity('0'));
        $this->assertFalse(UsedGoodsMargin::isWholePositiveQuantity('0.000'));
        $this->assertFalse(UsedGoodsMargin::isWholePositiveQuantity('1.5'));
        $this->assertFalse(UsedGoodsMargin::isWholePositiveQuantity('1.001'));
        $this->assertFalse(UsedGoodsMargin::isWholePositiveQuantity('-1'));
        $this->assertFalse(UsedGoodsMargin::isWholePositiveQuantity('abc'));
        $this->assertFalse(UsedGoodsMargin::isWholePositiveQuantity(''));
    }
}
