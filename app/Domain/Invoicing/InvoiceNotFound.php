<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use DomainException;

/**
 * Faktura pod daným klíčem v aktuální organizaci neexistuje — buď byla
 * mezitím smazána, nebo klíč patří cizí organizaci. Obojí se navenek
 * chová stejně (fail closed, žádné rozlišení pro útočníka).
 */
final class InvoiceNotFound extends DomainException
{
    public static function forKey(mixed $key): self
    {
        return new self(sprintf('Faktura #%s v této organizaci neexistuje.', (string) $key));
    }
}
