<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use RuntimeException;

/**
 * Souběžná sada odmítla sáhnout na databázi, která neprošla bezpečnostní
 * kontrolou. Fail closed: raději spadne celá sada, než aby `migrate:fresh`
 * zahodil schéma, které mu nepatří.
 */
final class UnsafeConcurrencyDatabase extends RuntimeException
{
    public static function forDriver(string $driver): self
    {
        return new self(sprintf(
            'Souběžná sada běží jen nad MariaDB/MySQL, aktivní driver je "%s". '
            .'Spusťte ji přes `composer test:concurrency`.',
            $driver,
        ));
    }

    public static function forMissingName(): self
    {
        return new self(
            'Nepodařilo se zjistit jméno aktivní databáze — bez něj se destruktivní operace nespustí.'
        );
    }

    public static function forMismatch(string $configured, string $active): self
    {
        return new self(sprintf(
            'Konfigurace spojení ukazuje na databázi "%s", ale SELECT DATABASE() vrací "%s". '
            .'Dokud se neshodují, souběžná sada nesmí spustit migrate:fresh.',
            $configured,
            $active,
        ));
    }

    public static function forDisallowedName(string $name): self
    {
        return new self(sprintf(
            'Databáze "%s" není povolená pro destruktivní souběžnou sadu. Povolena je výhradně "%s"; '
            .'jiné jméno vyžaduje MKF_CONCURRENCY_DATABASE=<jméno_test> a '
            .'MKF_CONCURRENCY_DATABASE_CONFIRM=%s. Vývojová ani produkční databáze povolit nejde.',
            $name,
            ConcurrencyDatabaseGuard::DEFAULT_DATABASE,
            ConcurrencyDatabaseGuard::CONFIRMATION,
        ));
    }
}
