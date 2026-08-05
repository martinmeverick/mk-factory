<?php

declare(strict_types=1);

namespace App\Domain\Contacts;

/**
 * Normalizace a kontrola českého IČO (8 číslic, kontrolní číslice mod 11).
 *
 * Kontrola se používá jako předfiltr před dotazem do ARESu — ušetří zbytečné
 * volání externí služby. NEPOUŽÍVÁ se jako tvrdá validace ručně zadaného IČO
 * u kontaktů, aby se zpětně neznehodnotily už uložené záznamy.
 */
final class CzechIco
{
    /**
     * Odstraní mezery a doplní zleva nulami na 8 číslic.
     * Vrací null, pokud vstup nelze na IČO převést.
     */
    public static function normalize(?string $ico): ?string
    {
        if ($ico === null) {
            return null;
        }

        $digits = preg_replace('/\s+/', '', trim($ico));

        if ($digits === '' || ! preg_match('/^\d{1,8}$/', (string) $digits)) {
            return null;
        }

        return str_pad((string) $digits, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Ověří formát i kontrolní číslici (váhy 8…2, modulo 11).
     */
    public static function isValid(?string $ico): bool
    {
        $normalized = self::normalize($ico);

        if ($normalized === null) {
            return false;
        }

        $sum = 0;

        for ($position = 0; $position < 7; $position++) {
            $sum += (int) $normalized[$position] * (8 - $position);
        }

        $remainder = $sum % 11;

        $checkDigit = match ($remainder) {
            0 => 1,
            1 => 0,
            default => 11 - $remainder,
        };

        return $checkDigit === (int) $normalized[7];
    }
}
