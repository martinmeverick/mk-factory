<?php

declare(strict_types=1);

namespace App\Domain\Money;

use DomainException;

/**
 * Peněžní hodnota se nevejde do podporovaného rozsahu (signed BIGINT
 * v haléřích). Vyhazuje se MÍSTO tichého castu na int, který by saturoval
 * na PHP_INT_MAX a uložil nesmyslnou částku.
 */
final class MoneyOverflow extends DomainException
{
    public static function forValue(string $decimalMinor, string $operation): self
    {
        return new self(
            "Peněžní hodnota překračuje podporovaný rozsah ({$operation}): {$decimalMinor} haléřů. "
            .'Maximum je '.Money::MAX_MINOR.' haléřů.'
        );
    }
}
