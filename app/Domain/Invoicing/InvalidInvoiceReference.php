<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use DomainException;

/**
 * Faktura odkazuje na záznam, který neexistuje nebo patří jiné organizaci.
 *
 * Doménová služba si kontrakt hlídá SAMA — HTTP validace je jen jedna
 * z možných cest a nesmí být jediné místo, kde tenant izolace referencí
 * platí. Rozhoduje se vždy proti organization_id ZAMČENÉ faktury, nikdy
 * proti ambientnímu tenant contextu.
 */
class InvalidInvoiceReference extends DomainException
{
    public static function forAttribute(string $attribute, mixed $value): self
    {
        return new self(sprintf(
            'Reference "%s" = %s neexistuje nebo patří jiné organizaci.',
            $attribute,
            is_scalar($value) ? (string) $value : gettype($value),
        ));
    }
}
