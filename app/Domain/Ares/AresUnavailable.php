<?php

declare(strict_types=1);

namespace App\Domain\Ares;

use RuntimeException;
use Throwable;

/**
 * Registr ARES je dočasně nedostupný (timeout, výpadek sítě, chyba 5xx)
 * nebo je integrace vypnutá konfigurací.
 */
final class AresUnavailable extends RuntimeException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self("Registr ARES není dostupný: {$reason}", previous: $previous);
    }

    public static function disabled(): self
    {
        return new self('Načítání z ARESu je vypnuté v konfiguraci.');
    }
}
