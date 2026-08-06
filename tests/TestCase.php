<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pojistka: žádný test nesmí volat skutečnou síť (typicky ARES).
        // Test, který HTTP potřebuje, si musí explicitně nastavit Http::fake().
        Http::preventStrayRequests();

        $this->assertRunningOnDisposableDatabase();
    }

    /**
     * Hlavní sada běží přes RefreshDatabase, tedy spouští `migrate:fresh`
     * — destruktivní operaci. Musí proto jet výhradně nad SQLite in-memory
     * databází, která zanikne s procesem.
     *
     * Samotné phpunit.xml jako hranice nestačí: bez force="true" proměnná
     * prostředí shellu hodnotu z XML přebije a `DB_URL=mysql://…/mk_factory`
     * by z obyčejného `composer test` udělal nástroj na smazání vývojové
     * databáze. XML tu díru zavírá, tahle kontrola ji hlídá i tehdy, když
     * konfigurace přijde odjinud (např. bootstrap/cache/config.php).
     */
    private function assertRunningOnDisposableDatabase(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $database = (string) $connection->getDatabaseName();

        if ($driver === 'sqlite' && ($database === ':memory:' || $database === '')) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Hlavní testovací sada smí běžet jen nad SQLite in-memory databází, '
            .'protože přes RefreshDatabase spouští migrate:fresh. Aktivní spojení je '
            .'driver "%s", databáze "%s" — sada se zastavila, aby ji nesmazala. '
            .'Zkontrolujte proměnné prostředí DB_CONNECTION, DB_DATABASE a DB_URL. '
            .'Testy souběhu nad MariaDB se spouští odděleně: composer test:concurrency.',
            $driver,
            $database,
        ));
    }
}
