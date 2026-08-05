<?php

declare(strict_types=1);

namespace App\Domain\Contacts;

use DomainException;

/**
 * E-mail v organizaci odpovídá více kontaktům, a chybí spolehlivější klíč
 * (external_id nebo IČO). Fakturu NIKDY nepřiřazujeme k náhodně vybrané
 * osobě podle pořadí v databázi — volající musí dodat jednoznačný klíč.
 */
final class AmbiguousCustomerMatch extends DomainException
{
    /**
     * Nové external_id, ale IČO už v organizaci patří jinému kontaktu.
     * Sloučit identity odhadem nelze — rozhodnutí patří člověku.
     */
    public static function forConflictingIco(string $ico, string $externalId): self
    {
        return new self(sprintf(
            'IČO %s už v organizaci patří jinému kontaktu, ale objednávka nese nové '
            .'external_id %s. Sjednoťte kontakty ručně — automatické sloučení není bezpečné.',
            $ico,
            $externalId,
        ));
    }

    public static function forEmail(string $email, int $matches): self
    {
        return new self(sprintf(
            'E-mail %s odpovídá %d kontaktům. Doplňte external_id nebo IČO — '
            .'fakturu nelze přiřadit odhadem.',
            $email,
            $matches,
        ));
    }
}
