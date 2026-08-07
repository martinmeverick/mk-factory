<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sonda pro důkaz POŘADÍ, ne běžný test.
 *
 * Leží v `tests/Support`, které neskenuje ani `phpunit.xml`, ani
 * `phpunit.concurrency.xml` — v žádné sadě se tedy sama nespustí. Spouští
 * ji výhradně `Tests\Feature\Testing\PrimaryTestDatabaseGuardTest`
 * v odděleném procesu.
 *
 * Přepisuje `migrateDatabases()`, tedy PRÁVĚ TU metodu, kterou
 * `RefreshDatabase` volá jako první destruktivní krok (`migrate:fresh`).
 * Než ji pustí dál, položí marker soubor. Když marker po běhu neexistuje,
 * je to důkaz, že se framework ke schématu vůbec nedostal — ne jen že
 * nakonec někde vznikla výjimka.
 */
final class DestructiveMigrationProbeTest extends TestCase
{
    // Alias schválně: `parent::migrateDatabases()` neexistuje — metodu
    // vkládá traita do TÉTO třídy, ne do předka.
    use RefreshDatabase {
        RefreshDatabase::migrateDatabases as frameworkMigrateDatabases;
    }

    /**
     * Cesta k marker souboru; sonda ji čte z prostředí, aby volající
     * proces mohl pracovat s vlastním dočasným adresářem.
     */
    public const string MARKER_VARIABLE = 'MKF_DESTRUCTIVE_MARKER';

    protected function migrateDatabases(): void
    {
        $marker = $_SERVER[self::MARKER_VARIABLE] ?? $_ENV[self::MARKER_VARIABLE] ?? getenv(self::MARKER_VARIABLE);

        if (is_string($marker) && $marker !== '') {
            file_put_contents($marker, 'migrate:fresh');
        }

        $this->frameworkMigrateDatabases();
    }

    public function test_probe_reaches_the_database(): void
    {
        $this->assertTrue(true);
    }
}
