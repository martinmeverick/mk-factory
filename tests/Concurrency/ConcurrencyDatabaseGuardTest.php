<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * RE-REVIEW, nález 1 + CLOSURE REVIEW, nález 3: souběžná sada volá
 * `migrate:fresh`, který ZAHODÍ celé schéma.
 *
 * Nejdřív stačilo, že driver je mysql — jméno databáze se nekontrolovalo
 * vůbec (re-review reprodukovalo 22 tabulek v podstrčené DB). Pak se
 * kontrolovalo jméno, ale ne SERVER: `DB_PORT=1` přepsalo hodnotu z XML
 * i přes `force="true"`, takže by stejně pojmenované schéma na jiném
 * dosažitelném serveru prošlo. Guard proto ověřuje celý endpoint
 * (spojení, host, port, databáze) z VÝSLEDNÉ konfigurace.
 *
 * Čistý kontrakt endpointu je v rychlé sadě
 * (`Tests\Unit\ConcurrencyDatabaseGuardEndpointTest`); tady se ověřuje
 * chování nad SKUTEČNÝM serverem a celý proces od začátku do konce.
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
     * Jméno spojení, pod kterým se sondy připojují.
     */
    private const string PROBE_CONNECTION = 'guard_probe';

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
            DB::purge(self::PROBE_CONNECTION);
            DB::statement('DROP DATABASE IF EXISTS `'.$schema.'`');
        }

        $this->createdSchemas = [];

        DB::purge(self::PROBE_CONNECTION);
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
            'database.connections.'.self::PROBE_CONNECTION => array_merge(
                config('database.connections.mysql'),
                ['database' => $configuredAs ?? $database],
            ),
        ]);

        DB::purge(self::PROBE_CONNECTION);
        $connection = DB::connection(self::PROBE_CONNECTION);

        // Konfigurace může schválně lhát (test nesouladu) — skutečné
        // aktivní schéma nastavíme explicitně.
        if ($configuredAs !== null) {
            $connection->statement('USE `'.$database.'`');
        }

        return $connection;
    }

    /**
     * Úplný opt-in na endpoint sondy. Sonda se připojuje pod jiným jménem
     * spojení než výchozí `mysql`, takže i ona je z pohledu závory
     * „alternativní endpoint" a musí být potvrzená celá.
     *
     * @return array<string, string>
     */
    private function probeOptIn(string $database): array
    {
        return [
            'connection' => self::PROBE_CONNECTION,
            'host' => ConcurrencyDatabaseGuard::DEFAULT_HOST,
            'port' => ConcurrencyDatabaseGuard::DEFAULT_PORT,
            'database' => $database,
            'confirm' => ConcurrencyDatabaseGuard::CONFIRMATION,
        ];
    }

    private function tableCount(string $schema): int
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ?',
            [$schema],
        )->c;
    }

    /**
     * Spustí celou souběžnou sadu (jeden rychlý test, který v setUp()
     * SKUTEČNĚ volá migrate:fresh) v odděleném procesu se zadaným
     * prostředím.
     *
     * @param  array<string, string>  $environment
     * @return array{0: int, 1: string}
     */
    private function runSuite(array $environment): array
    {
        $prefix = '';

        foreach ($environment as $key => $value) {
            $prefix .= $key.'='.escapeshellarg($value).' ';
        }

        $command = sprintf(
            'cd %s && %s./vendor/bin/phpunit -c phpunit.concurrency.xml '
            .'tests/Concurrency/BarrierContractTest.php '
            .'--filter test_worker_with_exactly_one_barrier_call_passes 2>&1',
            escapeshellarg(base_path()),
            $prefix,
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    // ---------- povolený endpoint ----------

    public function test_the_designated_test_database_passes(): void
    {
        ConcurrencyDatabaseGuard::assertSafe(DB::connection());

        $this->assertSame(
            ConcurrencyDatabaseGuard::DEFAULT_DATABASE,
            DB::connection()->getDatabaseName(),
            'Sada musí běžet nad mk_factory_test.',
        );
        $this->assertSame(ConcurrencyDatabaseGuard::DEFAULT_CONNECTION, DB::connection()->getName());
        $this->assertSame(ConcurrencyDatabaseGuard::DEFAULT_HOST, (string) DB::connection()->getConfig('host'));
        $this->assertSame(ConcurrencyDatabaseGuard::DEFAULT_PORT, (string) DB::connection()->getConfig('port'));
    }

    // ---------- jméno databáze ----------

    public function test_overridden_database_is_rejected_and_stays_untouched(): void
    {
        $this->createTemporarySchema(self::PROBE_PLAIN);
        $before = $this->tableCount(self::PROBE_PLAIN);

        try {
            ConcurrencyDatabaseGuard::assertSafe(
                $this->connectionTo(self::PROBE_PLAIN),
                $this->probeOptIn(self::PROBE_PLAIN),
            );
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
     * u `<env>` zapisuje putenv a $_ENV, ale ne $_SERVER, a Laravel čte
     * $_SERVER dřív — brzdou je až tahle PHP kontrola.)
     */
    public function test_running_the_suite_against_an_overridden_database_aborts_before_migrating(): void
    {
        $this->createTemporarySchema(self::PROBE_SUFFIXED);

        [$exitCode, $output] = $this->runSuite(['DB_DATABASE' => self::PROBE_SUFFIXED]);

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
            ConcurrencyDatabaseGuard::assertSafe(
                $connection,
                $this->probeOptIn(ConcurrencyDatabaseGuard::DEFAULT_DATABASE),
            );
            $this->fail('Nesoulad konfigurace a SELECT DATABASE() musí být odmítnut.');
        } catch (UnsafeConcurrencyDatabase $e) {
            $this->assertStringContainsString('SELECT DATABASE()', $e->getMessage());
            $this->assertStringContainsString(self::PROBE_SUFFIXED, $e->getMessage());
        }

        $this->assertSame(0, $this->tableCount(self::PROBE_SUFFIXED));
    }

    // ---------- identita serveru ----------

    /**
     * Jádro nálezu 3: schéma `mk_factory_test` může existovat na
     * libovolném serveru. Špatný port musí skončit ROZHODNUTÍM ZÁVORY,
     * ne síťovou chybou — tedy ještě než se Laravel vůbec pokusí připojit.
     */
    public function test_an_overridden_port_aborts_with_the_guard_not_a_connection_error(): void
    {
        [$exitCode, $output] = $this->runSuite(['DB_PORT' => '1']);

        $this->assertNotSame(0, $exitCode, "Sada musela selhat. Výstup:\n{$output}");
        $this->assertStringContainsString('port: o', $output, "Odmítnout to musela závora. Výstup:\n{$output}");
        $this->assertStringNotContainsString(
            'SQLSTATE',
            $output,
            "Závora musí rozhodnout PŘED pokusem o spojení. Výstup:\n{$output}",
        );
    }

    public function test_an_overridden_host_aborts_with_the_guard_not_a_connection_error(): void
    {
        [$exitCode, $output] = $this->runSuite(['DB_HOST' => '10.255.255.1']);

        $this->assertNotSame(0, $exitCode, "Sada musela selhat. Výstup:\n{$output}");
        $this->assertStringContainsString('host: o', $output, "Odmítnout to musela závora. Výstup:\n{$output}");
        $this->assertStringNotContainsString(
            'SQLSTATE',
            $output,
            "Závora musí rozhodnout PŘED pokusem o spojení. Výstup:\n{$output}",
        );
    }

    /**
     * `DB_URL` přepíše host i port, aniž by se `DB_HOST`/`DB_PORT` změnily.
     * Závora proto musí číst výslednou runtime konfiguraci.
     *
     * Jméno databáze je schválně to POVOLENÉ — přesně tak vypadá scénář
     * z nálezu: jiný dosažitelný server se schématem `mk_factory_test`.
     * Rozhodnout to musí závora, ne pokus o spojení.
     */
    public function test_a_database_url_pointing_at_another_host_aborts_before_connecting(): void
    {
        [$exitCode, $output] = $this->runSuite([
            'DB_URL' => 'mysql://root@10.255.255.1:3306/'.ConcurrencyDatabaseGuard::DEFAULT_DATABASE,
        ]);

        $this->assertNotSame(0, $exitCode, "Sada musela selhat. Výstup:\n{$output}");
        $this->assertStringContainsString('host: o', $output, "Odmítnout to musela závora. Výstup:\n{$output}");
        $this->assertStringNotContainsString(
            'SQLSTATE',
            $output,
            "Závora musí rozhodnout PŘED pokusem o spojení. Výstup:\n{$output}",
        );
    }

    public function test_a_database_url_pointing_at_another_port_aborts_before_connecting(): void
    {
        [$exitCode, $output] = $this->runSuite([
            'DB_URL' => 'mysql://root@127.0.0.1:1/'.ConcurrencyDatabaseGuard::DEFAULT_DATABASE,
        ]);

        $this->assertNotSame(0, $exitCode, "Sada musela selhat. Výstup:\n{$output}");
        $this->assertStringContainsString('port: o', $output, "Odmítnout to musela závora. Výstup:\n{$output}");
        $this->assertStringNotContainsString(
            'SQLSTATE',
            $output,
            "Závora musí rozhodnout PŘED pokusem o spojení. Výstup:\n{$output}",
        );
    }

    /**
     * `DB_URL` umí přesměrovat i jméno schématu — podstrčené schéma musí
     * zůstat netknuté.
     */
    public function test_a_database_url_pointing_at_another_schema_leaves_it_untouched(): void
    {
        $this->createTemporarySchema(self::PROBE_SUFFIXED);

        [$exitCode, $output] = $this->runSuite([
            'DB_URL' => 'mysql://root@127.0.0.1:3306/'.self::PROBE_SUFFIXED,
        ]);

        $this->assertNotSame(0, $exitCode, "Sada musela selhat. Výstup:\n{$output}");
        $this->assertStringContainsString(self::PROBE_SUFFIXED, $output);
        $this->assertSame(
            0,
            $this->tableCount(self::PROBE_SUFFIXED),
            'V podstrčeném schématu nesmí vzniknout ani zmizet žádná tabulka.',
        );
    }

    // ---------- opt-in ----------

    public function test_opt_in_allows_a_dedicated_test_schema_only_when_fully_confirmed(): void
    {
        $this->createTemporarySchema(self::PROBE_SUFFIXED);
        $connection = $this->connectionTo(self::PROBE_SUFFIXED);

        try {
            ConcurrencyDatabaseGuard::assertSafe(
                $connection,
                ['confirm' => null] + $this->probeOptIn(self::PROBE_SUFFIXED),
            );
            $this->fail('Opt-in bez potvrzení musí být odmítnut.');
        } catch (UnsafeConcurrencyDatabase) {
            // očekáváno
        }

        ConcurrencyDatabaseGuard::assertSafe($connection, $this->probeOptIn(self::PROBE_SUFFIXED));

        $this->assertSame(0, $this->tableCount(self::PROBE_SUFFIXED), 'Ani úspěšná kontrola nesmí nic vytvořit.');
    }

    /**
     * Alternativní endpoint bez opt-inu musí spadnout, s ÚPLNÝM opt-inem
     * projít — a to celý proces od začátku do konce, včetně migrace.
     */
    public function test_an_alternative_endpoint_needs_a_complete_opt_in_end_to_end(): void
    {
        $this->createTemporarySchema(self::PROBE_SUFFIXED);

        [$exitCode, $output] = $this->runSuite(['DB_DATABASE' => self::PROBE_SUFFIXED]);

        $this->assertNotSame(0, $exitCode, "Bez opt-inu musela sada selhat. Výstup:\n{$output}");
        $this->assertSame(0, $this->tableCount(self::PROBE_SUFFIXED), 'Bez opt-inu se nesmí nic vytvořit.');

        [$exitCode, $output] = $this->runSuite([
            'DB_DATABASE' => self::PROBE_SUFFIXED,
            'MKF_CONCURRENCY_CONNECTION' => ConcurrencyDatabaseGuard::DEFAULT_CONNECTION,
            'MKF_CONCURRENCY_HOST' => ConcurrencyDatabaseGuard::DEFAULT_HOST,
            'MKF_CONCURRENCY_PORT' => ConcurrencyDatabaseGuard::DEFAULT_PORT,
            'MKF_CONCURRENCY_DATABASE' => self::PROBE_SUFFIXED,
            'MKF_CONCURRENCY_DATABASE_CONFIRM' => ConcurrencyDatabaseGuard::CONFIRMATION,
        ]);

        $this->assertSame(0, $exitCode, "S úplným opt-inem měla sada projít. Výstup:\n{$output}");
        $this->assertGreaterThan(
            0,
            $this->tableCount(self::PROBE_SUFFIXED),
            'S opt-inem se nad potvrzeným endpointem migrovat SMÍ.',
        );
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
