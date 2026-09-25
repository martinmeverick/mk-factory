<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Money\InvalidInvoiceDiscount;
use App\Domain\Money\InvoiceDiscount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceDiscountTest extends TestCase
{
    public function test_fixed_discount_is_exact_and_rounding_is_stable(): void
    {
        $calculator = new InvoiceDiscount;
        self::assertSame([66667, 66667, 66666], $calculator->allocate([100000, 100000, 100000], 'fixed', '2000'));
        self::assertSame([1, 0, 0], $calculator->allocate([1, 1, 1], 'fixed', '0.01'));
        self::assertSame([0, 1], $calculator->allocate([0, 100], 'fixed', '0.01'));
        self::assertSame([12100, 11200, 0], $calculator->allocate([121000, 112000, 0], 'percent', '10'));
    }

    public function test_large_values_never_use_float_or_overflow_intermediate_products(): void
    {
        $values = [4000000000000000000, 4000000000000000000];
        self::assertSame([2000000000000000000, 2000000000000000000], (new InvoiceDiscount)->allocate($values, 'percent', '50'));
    }

    public function test_legacy_no_discount_preserves_negative_line_support(): void
    {
        self::assertSame([0, 0], (new InvoiceDiscount)->allocate([10000, -1000], 'none', '0.00'));
    }

    #[DataProvider('invalidDiscounts')]
    public function test_invalid_discounts_are_rejected(array $gross, string $type, string $value): void
    {
        $this->expectException(InvalidInvoiceDiscount::class);
        (new InvoiceDiscount)->allocate($gross, $type, $value);
    }

    public static function invalidDiscounts(): array
    {
        return [
            [[100], 'percent', '100.01'], [[100], 'fixed', '1.01'],
            [[100], 'fixed', '-1'], [[100], 'none', '1'],
            [[100], 'invalid', '1'], [[100, -1], 'percent', '5'],
            [[0], 'fixed', '1'], [[100], 'percent', '1e2'],
        ];
    }
}
