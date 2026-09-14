<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Způsob zadání odběratele ve formuláři vydané faktury.
 *
 * Chybějící/prázdná hodnota = výběr z kontaktů (zpětná kompatibilita
 * současného API i testů); neznámá hodnota je validační chyba.
 */
enum InvoiceRecipientMode: string
{
    case Existing = 'existing';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Existing => 'Vybrat z kontaktů',
            self::Manual => 'Zadat fyzickou osobu',
        };
    }
}
