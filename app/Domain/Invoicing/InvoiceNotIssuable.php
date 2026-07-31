<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use DomainException;

class InvoiceNotIssuable extends DomainException
{
    public static function because(string $reason): self
    {
        return new self('Fakturu nelze vystavit: '.$reason);
    }
}
