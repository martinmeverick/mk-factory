<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use DomainException;

class InvalidStateTransition extends DomainException
{
    public static function between(string $from, string $to): self
    {
        return new self(sprintf('Nepovolený přechod stavu faktury: %s → %s.', $from, $to));
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
