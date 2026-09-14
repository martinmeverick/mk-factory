<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use DomainException;

/**
 * Platby jsou append-only záznam o skutečnosti. Vznikají výhradně
 * v lifecycle službách (registerPayment/markPaid) a poté se nemění ani
 * nemažou — jinak by se rozešly s denormalizovaným `paid_amount_minor`
 * a se stavem dokladu. Storno platby jako operace zatím neexistuje
 * (viz FUTURE_BACKLOG.md).
 */
class ImmutablePaymentViolation extends DomainException
{
    public static function forUpdate(): self
    {
        return new self('Platba je záznam o skutečnosti — nelze ji dodatečně měnit.');
    }

    public static function forDeletion(): self
    {
        return new self('Platba je záznam o skutečnosti — nelze ji mazat.');
    }
}
