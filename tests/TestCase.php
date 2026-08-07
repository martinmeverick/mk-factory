<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PrimaryTestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Závora destruktivní sady.
     *
     * `refreshApplication()` volá Laravel v `setUpTheTestEnvironment()`
     * PŘED `setUpTraits()`, a teprve `setUpTraits()` spouští
     * `RefreshDatabase::refreshDatabase()` → `migrate:fresh`. Kontrola
     * tedy běží:
     *
     *   - po bootstrapu aplikace a načtení konfigurace,
     *   - po sestavení konfigurace spojení (včetně aplikace `DB_URL`),
     *   - ale PŘED první destruktivní operací frameworku,
     *
     * a to bez ohledu na to, který traity test používá (`RefreshDatabase`,
     * `DatabaseMigrations`, `DatabaseTruncation` i žádný).
     *
     * ## Proč NE `beforeRefreshingDatabase()`
     *
     * Ten hook by byl sémanticky přesnější, ale v ABSTRAKTNÍM PŘEDKOVI
     * nefunguje: PHP dává metodě z traity přednost před zděděnou metodou
     * předka. Jakmile potomek použije `use RefreshDatabase;`, vloží se do
     * něj prázdná `beforeRefreshingDatabase()` z traity a ta implementaci
     * z `Tests\TestCase` PŘEBIJE — závora by byla mrtvý kód. Ověřeno.
     *
     * Dřív se kontrola volala až v `setUp()` PO `parent::setUp()`, tedy až
     * po `migrate:fresh`. Re-review to reprodukovalo: podstrčené `DB_URL`
     * na dočasné MySQL schéma nechalo vzniknout 22 tabulek a teprve potom
     * sada spadla. Guard tak nebyl bezpečnostní bariéra, jen pozdní
     * diagnostika.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        PrimaryTestDatabaseGuard::assertDisposable($this->app['db'], $this->app['config']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Pojistka: žádný test nesmí volat skutečnou síť (typicky ARES).
        // Test, který HTTP potřebuje, si musí explicitně nastavit Http::fake().
        Http::preventStrayRequests();

        // Druhá kontrola po celém setUpu — zachytí i konfiguraci
        // přepsanou v průběhu (traita, service provider, samotný test).
        // Bariérou je ale ta v refreshApplication() výše.
        PrimaryTestDatabaseGuard::assertDisposable($this->app['db'], $this->app['config']);
    }
}
