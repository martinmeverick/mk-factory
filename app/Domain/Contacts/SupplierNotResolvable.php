<?php

declare(strict_types=1);

namespace App\Domain\Contacts;

use DomainException;

/**
 * Dodavatele nelze podle zadaného IČO určit — v organizaci zatím není
 * a nepodařilo se získat ani název (ARES nedostupný nebo subjekt neexistuje).
 */
final class SupplierNotResolvable extends DomainException
{
    public static function unknownIco(string $ico): self
    {
        return new self("IČO {$ico} se nepodařilo najít v registru ARES. Zkontrolujte ho, nebo zadejte název dodavatele ručně.");
    }

    public static function registryUnavailable(): self
    {
        return new self('Registr ARES je nedostupný. Zadejte prosím název dodavatele ručně.');
    }
}
