<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Money\Money;

/**
 * Formátovací pomocníci pro Blade šablony.
 */
final class Format
{
    public static function money(int $minor, string $currency = 'CZK'): string
    {
        return Money::fromMinor($minor, $currency)->formatCzech();
    }

    public static function date(mixed $date): string
    {
        if ($date === null) {
            return '—';
        }

        return \Illuminate\Support\Carbon::parse($date)->format('d.m.Y');
    }

    /**
     * Množství bez zbytečných nul: '2.500' → '2,5'; '1.000' → '1'.
     */
    public static function quantity(string|float|int $quantity): string
    {
        $trimmed = rtrim(rtrim(number_format((float) $quantity, 3, '.', ''), '0'), '.');

        return str_replace('.', ',', $trimmed === '' ? '0' : $trimmed);
    }

    /**
     * Sazba DPH: '21.00' → '21 %'.
     */
    public static function vatRate(string|float|null $rate): string
    {
        if ($rate === null) {
            return '—';
        }

        return self::quantity((string) $rate).' %';
    }
}
