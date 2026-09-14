<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use DomainException;

/**
 * Příloha pod daným klíčem u zamčené faktury v aktuální organizaci
 * neexistuje — buď ji mezitím smazal někdo jiný, nebo klíč patří jinému
 * dokladu či jiné organizaci. Obojí se navenek chová stejně (fail closed,
 * žádné rozlišení pro útočníka), stejně jako u InvoiceNotFound.
 */
final class AttachmentNotFound extends DomainException
{
    public static function forKey(mixed $key): self
    {
        return new self(sprintf('Příloha #%s u této faktury neexistuje.', (string) $key));
    }
}
