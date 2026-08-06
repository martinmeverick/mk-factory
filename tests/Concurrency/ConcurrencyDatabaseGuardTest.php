<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * RE-REVIEW, nález 1: souběžná sada volá `migrate:fresh`, který ZAHODÍ celé
 * schéma. Dřív stačilo, že driver je mysql — jméno databáze se nekontrolovalo
 * vůbec, takže `export DB_DATABASE=…` přesměroval destruktivní operaci na
 * cizí databázi (re-review to reprodukovalo: 22 tabulek v podstrčené DB).
 *
 * Tenhle test NEDĚDÍ z ConcurrencyTestCase schválně — ten v setUp() migruje.
 * Pracuje výhradně nad DOČASNÝMI izolovanými schématy, která si sám vytvoří
 * a v tearDown() zase zahodí. Vývojové ani produkční databáze se nedotýká.
 */
class ConcurrencyDatabaseGuardTest extends BaseTestCase
{
    /**
     * Jméno bez suffixu `_test` — neprojde ani s opt-inem.
     */
    private const string PROBE_PLAIN = 'mkf_guard_probe';

    /**
     * Jméno se suffixem `_test` — projde jen s úplným opt-inem.
     */
    private const string PROBE_SUFFIXED = 'mkf_guard_probe_test';

    /**
     * @var list<string>
     */
    private array $createdSchemas = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Kontrola databázové závory vyžaduje MariaDB/MySQL.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdSchemas as $schema) {
            DB::purge('guard_probe');
            DB::statement('DROP DATABASE IF EXISTS `'.$schema.'`');
        }

        $this->createdSchemas = [];

        DB::purge('guard_probe');
        DB::disconnect();

        parent::tearDown();
    }

    private function createTemporarySchema(string $name): void
    {
        DB::statement('CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4');
        $this->createdSchemas[] = $name;
    }

    private function connectionTo(string $database, ?string $configuredAs = null): Connection
    {
        config([
            'database.connections.guard_probe' => array_merge(
                config('database.connections.mysql'),
                ['database' => $configuredAs ?? $database],
            ),
        ]);

        DB::purge('guard_probe');
        $connection = DB::connection('guard_probe');

        // Konfigurace může schválně lhát (test nesouladu) — skutečné
        // aktivní schéma nastavíme explicitně.
        if ($configuredAs !== null) {
            $connection->statement('USE `'.$database.'`');
        }

        return $connection;
    }

    private function tableCount(string $schema): int
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ?',
            [$schema],
        )->c;
    }

    public function test_the_designated_test_database_passes(): void
    {
        ConcurrencyDatabaseGuard::assertSafe(DB::connection());

        $this->assertSame(
            ConcurrencyDatabaseGuard::DEFAULT_DATABASE,
            DB::connection()->getDatabaseName(),
            'Sada musí běžet nad mk_factory_test.',
        );
    }

    public function test_overridden_database_is_rejected_and_stays_untouched(): void
    {
        $this->createTemporarySchema(self::PROBE_PLAIN);
        $before = $this->tableCount(self::PROBE_PLAIN);

        try {
            ConcurrencyDatabaseGuard::assertSafe($this->connectionTo(self::PROBE_PLAIN));
            $this->fail('Podstrčená databáze musí být odmítnuta.');
        } catch (UnsafeConcurrencyDatabase $e) {
            $this->assertStringContainsString(self::PROBE_PLAIN, $e->getMessage());
        }

        $this->assertSame(0, $before, 'Sonda musí začínat prázdná.');
        $this->assertSame(
            0,
            $this->tableCount(self::PROBE_PLAIN),
            'V cizí databázi nesmí vzniknout ani zmizet žádná tabulka.',
        );
    }

    /**
     * Skutečný útočný scénář z re-review, celý proces od začátku do konce:
     * `DB_DATABASE=<jiná> composer test:concurrency`. Musí spadnout PŘED
     * migrate:fresh a podstrčené schéma nechat prázdné.
     *
     * (Ověřuje se tím i to, že `force="true"` v XML samo nestačí: PHPUnit
     * u <env> zapisuje putenv a $_ENV, ale ne $_SERVER, a Laravel čte
     * $_SERVER dřív — brzdou je až tahle PHP kontrola.)
     */
    public function test_running_the_suite_against_an_overridden_database_aborts_before_migrating(): void
    {
        $this->createTemporarySchema(self::PROBE_SUFFIXED);

        // Spouští se sada, která v setUp() SKUTEČNĚ volá migrate:fresh —
        // jinak by test neprokázal nic o destruktivní operaci.
        $command = sprintf(
            'cd %s && DB_DATABASE=%s ./vendor/bin/phpunit -c phpunit.concurrency.xml '
            .'tests/Concurrency/BarrierContractTest.php '
            .'--filter test_worker_with_exactly_one_barrier_call_passes 2>&1',
            escapeshellarg(base_path()),
            escapeshellarg(self::PROBE_SUFFIXED),
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        $output = implode("\n", $output);

        $this->assertNotSame(0, $exitCode, "Sada musela selhat. Výstup:\n{$output}");
        $this->assertStringContainsString(self::PROBE_SUFFIXED, $output);
        $this->assertSame(
            0,
            $this->tableCount(self::PROBE_SUFFIXED),
            'migrate:fresh se nesmí spustit — podstrčená databáze musí zůstat prázdná.',
        );
    }

    public function test_mismatch_between_configuration_and_active_schema_is_rejected(): void
    {
        $this->createTemporarySchema(self::PROBE_SUFFIXED);

        // Konfigurace tvrdí povolenou databázi, spojení je ale přepnuté
        // jinam — na to samotné getDatabaseName() nestačí.
        $connection = $this->connectionTo(
            self::PROBE_SUFFIXED,
            configuredAs: ConcurrencyDatabaseGuard::DEFAULT_DATABASE,
        );

        try {
            ConcurrencyDatabaseGuard::assertSafe($connection);
            $this->fail('Nesoulad konfigurace a SELECT DATABASE() musí být odmítnut.');
        } catch (UnsafeConcurrencyDatabase $e) {
            $this->assertStringContainsString('SELECT DATABASE()', $e->getMessage());
            $this->assertStringContainsString(self::PROBE_SUFFIXED, $e->getMessage());
        }

        $this->assertSame(0, $this->tableCount(self::PROBE_SUFFIXED));
    }

    public function test_opt_in_allows_a_dedicated_test_schema_only_when_fully_confirmed(): void
    {
        $this->createTemporarySchema(self::PROBE_SUFFIXED);
        $connection = $this->connectionTo(self::PROBE_SUFFIXED);

        try {
            ConcurrencyDatabaseGuard::assertSafe($connection, ['database' => self::PROBE_SUFFIXED, 'confirm' => null]);
            $this->fail('Opt-in bez potvrzení musí být odmítnut.');
        } catch (UnsafeConcurrencyDatabase) {
            // očekáváno
        }

        ConcurrencyDatabaseGuard::assertSafe($connection, [
            'database' => self::PROBE_SUFFIXED,
            'confirm' => ConcurrencyDatabaseGuard::CONFIRMATION,
        ]);

        $this->assertSame(0, $this->tableCount(self::PROBE_SUFFIXED), 'Ani úspěšná kontrola nesmí nic vytvořit.');
    }

    public function test_non_mysql_driver_is_rejected(): void
    {
        config([
            'database.connections.guard_sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('guard_sqlite');

        try {
            ConcurrencyDatabaseGuard::assertSafe(DB::connection('guard_sqlite'));
            $this->fail('SQLite spojení musí být odmítnuto.');
        } catch (UnsafeConcurrencyDatabase $e) {
            $this->assertStringContainsString('sqlite', $e->getMessage());
        } finally {
            DB::purge('guard_sqlite');
        }
    }
}
