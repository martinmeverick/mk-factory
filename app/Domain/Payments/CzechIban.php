<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use InvalidArgumentException;

/**
 * Výpočet a validace českého IBAN z tuzemského čísla účtu.
 *
 * Český BBAN = kód banky (4) + předčíslí doplněné na 6 + číslo účtu
 * doplněné na 10 číslic; kontrolní číslice dle ISO 13616 (mod-97).
 */
final class CzechIban
{
    private const CZ_IBAN_LENGTH = 24;

    /**
     * Sestaví CZ IBAN z předčíslí, čísla účtu a kódu banky.
     *
     * @throws InvalidArgumentException při nevalidním vstupu
     */
    public static function fromCzechAccount(?string $prefix, string $number, string $bankCode): string
    {
        $prefix = trim((string) $prefix);
        $number = trim($number);
        $bankCode = trim($bankCode);

        if ($prefix !== '' && ! preg_match('/^\d{1,6}$/', $prefix)) {
            throw new InvalidArgumentException(
                "Neplatné předčíslí účtu: „{$prefix}“ (povoleno max. 6 číslic)."
            );
        }

        if (! preg_match('/^\d{1,10}$/', $number)) {
            throw new InvalidArgumentException(
                "Neplatné číslo účtu: „{$number}“ (povoleno 1–10 číslic)."
            );
        }

        if (! preg_match('/^\d{4}$/', $bankCode)) {
            throw new InvalidArgumentException(
                "Neplatný kód banky: „{$bankCode}“ (musí být přesně 4 číslice)."
            );
        }

        $bban = $bankCode
            .str_pad($prefix, 6, '0', STR_PAD_LEFT)
            .str_pad($number, 10, '0', STR_PAD_LEFT);

        $checkDigits = self::checkDigits($bban, 'CZ');

        return 'CZ'.$checkDigits.$bban;
    }

    /**
     * Sestaví CZ IBAN z čísla účtu v běžném zápisu, např. „19-2000145399“
     * nebo „123456789“, a kódu banky.
     */
    public static function fromAccountNumber(string $accountNumber, string $bankCode): string
    {
        $accountNumber = trim($accountNumber);

        if (str_contains($accountNumber, '-')) {
            [$prefix, $number] = explode('-', $accountNumber, 2);

            return self::fromCzechAccount($prefix, $number, $bankCode);
        }

        return self::fromCzechAccount(null, $accountNumber, $bankCode);
    }

    /**
     * Obecná mod-97 kontrola IBAN; pro CZ navíc vyžaduje délku 24 znaků.
     */
    public static function isValid(string $iban): bool
    {
        $iban = strtoupper(str_replace([' ', "\u{A0}"], '', trim($iban)));

        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{1,30}$/', $iban)) {
            return false;
        }

        if (str_starts_with($iban, 'CZ') && strlen($iban) !== self::CZ_IBAN_LENGTH) {
            return false;
        }

        return self::mod97(substr($iban, 4).substr($iban, 0, 4)) === 1;
    }

    /**
     * Kontrolní číslice: 98 − (BBAN + kód země + „00“) mod 97, doplněno na 2 číslice.
     */
    private static function checkDigits(string $bban, string $countryCode): string
    {
        $remainder = self::mod97($bban.$countryCode.'00');

        return str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Mod-97 nad řetězcem, kde se písmena nahrazují čísly (A=10 … Z=35).
     * Počítáno po částech v celých číslech — bez bcmath, bez přetečení.
     */
    private static function mod97(string $input): int
    {
        $numeric = '';

        foreach (str_split($input) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (($remainder.$chunk) % 97);
        }

        return $remainder;
    }
}
