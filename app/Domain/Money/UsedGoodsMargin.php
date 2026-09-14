<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * Pravidla zvláštního režimu - použité zboží (§ 90 ZDPH) — jediné místo
 * pravdy pro povinný text na dokladu, podporované interní sazby a pravidlo
 * celých kusů. Kód NEROZHODUJE o právní způsobilosti prodeje pro tento režim;
 * režim volí explicitně uživatel.
 */
final class UsedGoodsMargin
{
    /** Povinný text na dokladu odběratele (§ 90 odst. 14 ZDPH). Přesné znění. */
    public const string CUSTOMER_NOTICE = 'zvláštní režim - použité zboží';

    /** Doplňkový text na dokladu — DPH z přirážky se odběrateli nevyčísluje. */
    public const string CUSTOMER_NOTICE_SUPPLEMENT = 'DPH se nevyčísluje.';

    /**
     * Podporované interní sazby DPH z přirážky v uloženém tvaru DECIMAL(5,2).
     * První verze: jen 21 % (použité telefony).
     */
    public const array SUPPORTED_VAT_RATES = ['21.00'];

    /** Zvláštní režim je v této verzi podporován jen v CZK. */
    public const string CURRENCY = 'CZK';

    /**
     * Volby sazby pro formulář (celé procento bez nul): ['21'].
     *
     * @return list<string>
     */
    public static function rateOptions(): array
    {
        return array_map(self::rateToOption(...), self::SUPPORTED_VAT_RATES);
    }

    /**
     * '21.00' → '21' (tvar pro formulářový select).
     */
    public static function rateToOption(string $storedRate): string
    {
        $rate = str_replace(',', '.', trim($storedRate));

        if (str_contains($rate, '.')) {
            $rate = rtrim(rtrim($rate, '0'), '.');
        }

        return $rate === '' ? '0' : $rate;
    }

    /**
     * Vstup '21' | '21,00' | '21.00' → uložený tvar '21.00'. Nepodporovaná
     * sazba vyhazuje výjimku (nikdy tichý fallback).
     */
    public static function normalizeRate(string $rate): string
    {
        $normalized = str_replace(',', '.', trim($rate));

        if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException("Neplatná sazba DPH z přirážky: {$rate}");
        }

        $stored = bcadd($normalized, '0', 2);

        if (! in_array($stored, self::SUPPORTED_VAT_RATES, true)) {
            throw new InvalidArgumentException("Nepodporovaná sazba DPH z přirážky: {$rate}");
        }

        return $stored;
    }

    public static function isSupportedRate(?string $rate): bool
    {
        if ($rate === null || $rate === '') {
            return false;
        }

        try {
            self::normalizeRate($rate);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Množství ve zvláštním režimu (použité telefony): celé kladné kusy.
     * Přijímá '1', '2.000', '3,000'; odmítá '0', '1.5', '-1'.
     */
    public static function isWholePositiveQuantity(string $quantity): bool
    {
        $qty = str_replace(',', '.', trim($quantity));

        if (! preg_match('/^\d+(\.\d+)?$/', $qty)) {
            return false;
        }

        if (bccomp($qty, '0', 3) <= 0) {
            return false;
        }

        // Ořez na celé číslo musí dát stejnou hodnotu (bez desetinné části).
        return bccomp($qty, bcadd($qty, '0', 0), 3) === 0;
    }
}
