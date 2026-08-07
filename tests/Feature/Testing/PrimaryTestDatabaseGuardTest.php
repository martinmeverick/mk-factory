<?php

declare(strict_types=1);

namespace Tests\Feature\Testing;

use PDO;
use Tests\Support\DestructiveMigrationProbeTest;
use Tests\Support\PrimaryTestDatabaseGuard;
use Tests\Support\UnsafePrimaryTestDatabase;
use Tests\TestCase;

/**
 * CLOSURE REVIEW, nález 2: runtime závora hlavní testovací sady běžela až
 * PO `parent::setUp()`. Laravel ale uvnitř rodičovského setupu stihne
 * `RefreshDatabase` → `migrate:fresh`, takže podstrčené `DB_URL` nechalo
 * v cizím schématu vzniknout 22 tabulek a teprve potom sada spadla.
 * Kontrola nebyla bariéra, jen pozdní diagnostika.
 *
 * Testy níž ověřují obojí: samotný KONTRAKT (co se smí a co ne) i POŘADÍ
 * (kontrola musí odmítnout dřív, než framework sáhne na schéma). Pořadí se
 * ověřuje v odděleném procesu přes sondu
 * `Tests\Support\DestructiveMigrationProbeTest`, která si přepisuje
 * `migrateDatabases()` — první destruktivní krok `RefreshDatabase`.
 * Chybějící marker je důkaz, že se framework ke schématu nedostal; samotná
 * výjimka by nedokázala nic.
 */
class PrimaryTestDatabaseGuardTest extends TestCase
{
    private ?string $workspace = null;

    protected function tearDown(): void
    {
        if ($this->workspace !== null) {
            foreach ((glob($this->workspace.'/*') ?: []) as $file) {
                @unlink($file);
            }

            @rmdir($this->workspace);
            $this->workspace = null;
        }

        parent::tearDown();
    }

    private function workspace(): string
    {
        if ($this->workspace === null) {
            $this->workspace = sys_get_temp_dir().'/mkf-primary-guard-'.bin2hex(random_bytes(8));
            mkdir($this->workspace, 0700, true);
        }

        return $this->workspace;
    }

    /**
     * @param  array<string, string>  $environment
     * @return array{0: int, 1: string}
     */
    private function runProbe(array $environment): array
    {
        $prefix = '';

        foreach ($environment as $key => $value) {
            $prefix .= $key.'='.escapeshellarg($value).' ';
        }

        $command = sprintf(
            'cd %s && %s./vendor/bin/phpunit -c phpunit.xml tests/Support/DestructiveMigrationProbeTest.php 2>&1',
            escapeshellarg(base_path()),
            $prefix,
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    // ---------- kontrakt ----------

    public function test_the_expected_in_memory_configuration_passes(): void
    {
        PrimaryTestDatabaseGuard::assertRuntimeConfiguration(
            PrimaryTestDatabaseGuard::EXPECTED_CONNECTION,
            ['driver' => 'sqlite', 'database' => ':memory:'],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Živá konfigurace téhle sady musí odpovídat očekávané identitě —
     * jinak by kontrakt výše hlídal něco jiného, než nad čím se běží.
     */
    public function test_the_live_test_connection_is_the_expected_in_memory_sqlite(): void
    {
        $name = (string) config('database.default');
        $connection = $this->app['db']->connection($name);

        $this->assertSame(PrimaryTestDatabaseGuard::EXPECTED_CONNECTION, $name);
        $this->assertSame(PrimaryTestDatabaseGuard::EXPECTED_DRIVER, $connection->getDriverName());
        $this->assertSame(PrimaryTestDatabaseGuard::EXPECTED_DATABASE, $connection->getDatabaseName());
        $this->assertNull(config('database.connections.'.$name.'.url') ?: null);
    }

    public function test_a_mysql_runtime_configuration_is_rejected(): void
    {
        $this->assertRejected(
            fn () => PrimaryTestDatabaseGuard::assertRuntimeConfiguration(
                'sqlite',
                ['driver' => 'mysql', 'database' => 'mk_factory', 'host' => '127.0.0.1', 'port' => 3306],
            ),
            'mysql',
        );
    }

    public function test_a_persistent_sqlite_file_is_rejected(): void
    {
        $this->assertRejected(
            fn () => PrimaryTestDatabaseGuard::assertRuntimeConfiguration(
                'sqlite',
                ['driver' => 'sqlite', 'database' => '/var/data/database.sqlite'],
            ),
            '/var/data/database.sqlite',
        );
    }

    public function test_a_foreign_connection_name_is_rejected(): void
    {
        $this->assertRejected(
            fn () => PrimaryTestDatabaseGuard::assertRuntimeConfiguration(
                'mysql',
                ['driver' => 'sqlite', 'database' => ':memory:'],
            ),
            'mysql',
        );
    }

    /**
     * Aktivní `DB_URL` se odmítá i tehdy, když by výsledná konfigurace
     * vypadala nevinně — `ConfigurationUrlParser` klíč `url` z výsledku
     * odstraní, takže by přesměrování jinak nebylo poznat jako příčina.
     */
    public function test_an_active_database_url_is_rejected(): void
    {
        $this->assertRejected(
            fn () => PrimaryTestDatabaseGuard::assertRuntimeConfiguration(
                'sqlite',
                ['driver' => 'sqlite', 'database' => ':memory:'],
                'mysql://root@127.0.0.1:3306/mk_factory',
            ),
            'DB_URL',
        );
    }

    public function test_a_read_write_split_is_rejected(): void
    {
        $this->assertRejected(
            fn () => PrimaryTestDatabaseGuard::assertRuntimeConfiguration(
                'sqlite',
                [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                    'read' => ['database' => '/var/data/database.sqlite'],
                ],
            ),
            'read',
        );
    }

    private function assertRejected(callable $call, string $expectedInMessage): void
    {
        try {
            $call();
            $this->fail('Konfigurace musí být odmítnuta.');
        } catch (UnsafePrimaryTestDatabase $e) {
            $this->assertStringContainsString($expectedInMessage, $e->getMessage());
        }
    }

    // ---------- pořadí: závora musí předběhnout migrate:fresh ----------

    /**
     * Sonda běží normálně: dokáže, že marker mechanismus funguje a že
     * `migrateDatabases()` se za běžných podmínek OPRAVDU volá. Bez toho
     * by chybějící marker v testech níž nedokazoval nic.
     */
    public function test_the_probe_reaches_the_destructive_step_on_the_expected_database(): void
    {
        $marker = $this->workspace().'/migrated';

        [$exitCode, $output] = $this->runProbe([DestructiveMigrationProbeTest::MARKER_VARIABLE => $marker]);

        $this->assertSame(0, $exitCode, "Sonda měla nad :memory: projít. Výstup:\n{$output}");
        $this->assertFileExists($marker, 'Sonda musí destruktivní krok frameworku skutečně zachytit.');
    }

    public function test_a_forged_database_url_aborts_before_the_framework_touches_the_schema(): void
    {
        $marker = $this->workspace().'/migrated';

        [$exitCode, $output] = $this->runProbe([
            'DB_URL' => 'mysql://root@127.0.0.1:3306/mkf_primary_guard_probe',
            DestructiveMigrationProbeTest::MARKER_VARIABLE => $marker,
        ]);

        $this->assertNotSame(0, $exitCode, "Sada musela selhat. Výstup:\n{$output}");
        $this->assertStringContainsString('DB_URL', $output);
        $this->assertFileDoesNotExist(
            $marker,
            'Závora musí odmítnout PŘED migrate:fresh — marker destruktivního kroku nesmí vzniknout.',
        );
    }

    /**
     * Podstrčená persistentní SQLite: marker v souboru musí přežít a
     * destruktivní krok se nesmí spustit.
     */
    public function test_a_persistent_sqlite_database_survives_the_aborted_run(): void
    {
        $database = $this->workspace().'/probe.sqlite';
        $marker = $this->workspace().'/migrated';

        $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE mkf_marker (note TEXT)');
        $pdo->exec("INSERT INTO mkf_marker (note) VALUES ('tuhle databázi nesmí nikdo smazat')");
        $tablesBefore = $this->sqliteTableNames($pdo);
        $pdo = null;

        $this->assertSame(['mkf_marker'], $tablesBefore);

        [$exitCode, $output] = $this->runProbe([
            'DB_DATABASE' => $database,
            DestructiveMigrationProbeTest::MARKER_VARIABLE => $marker,
        ]);

        $this->assertNotSame(0, $exitCode, "Sada musela selhat. Výstup:\n{$output}");
        $this->assertStringContainsString('probe.sqlite', $output);
        $this->assertFileDoesNotExist($marker, 'migrate:fresh se nesmí spustit.');

        $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->assertSame($tablesBefore, $this->sqliteTableNames($pdo), 'Schéma podstrčené databáze se nesmí změnit.');
        $this->assertSame(
            'tuhle databázi nesmí nikdo smazat',
            $pdo->query('SELECT note FROM mkf_marker')->fetchColumn(),
            'Marker musí přežít.',
        );
    }

    /**
     * @return list<string>
     */
    private function sqliteTableNames(PDO $pdo): array
    {
        $names = $pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_map(strval(...), $names);
    }
}
