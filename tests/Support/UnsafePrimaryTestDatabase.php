<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Hlavní sada odmítla běžet nad databází, která neprošla kontrolou.
 * Fail closed: raději spadne celá sada, než aby `RefreshDatabase` spustil
 * `migrate:fresh` nad něčím, co má přežít.
 */
final class UnsafePrimaryTestDatabase extends RuntimeException
{
    /**
     * @param  array<string, string|null>  $environment
     */
    public static function because(string $reason, array $environment): self
    {
        $inputs = [];

        foreach ($environment as $key => $value) {
            $inputs[] = $key.'='.($value === null ? '(nenastaveno)' : '"'.$value.'"');
        }

        return new self(sprintf(
            'Hlavní testovací sada smí běžet VÝHRADNĚ nad SQLite in-memory databází, protože '
            .'přes RefreshDatabase spouští migrate:fresh. %s '
            .'Očekáváno: spojení "%s", driver "%s", databáze "%s". '
            .'Vstupy prostředí: %s. '
            .'Sada se zastavila PŘED jakoukoli destruktivní operací. '
            .'Testy souběhu nad MariaDB se spouštějí odděleně: composer test:concurrency.',
            $reason,
            PrimaryTestDatabaseGuard::EXPECTED_CONNECTION,
            PrimaryTestDatabaseGuard::EXPECTED_DRIVER,
            PrimaryTestDatabaseGuard::EXPECTED_DATABASE,
            implode(', ', $inputs),
        ));
    }
}
