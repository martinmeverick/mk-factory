<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\ConfigurationUrlParser;
use PHPUnit\Framework\TestCase;
use Tests\Concurrency\ConcurrencyDatabaseGuard;
use Tests\Concurrency\UnsafeConcurrencyDatabase;

/**
 * CLOSURE REVIEW, nález 3: závora souběžné sady kontrolovala driver,
 * jméno schématu a shodu konfigurace se `SELECT DATABASE()`. NEkontrolovala
 * ale host, port ani jméno spojení. Re-review potvrdilo, že `DB_PORT=1`
 * přepsalo hodnotu z XML i přes `force="true"` a Laravel se skutečně
 * pokusil připojit na port 1 — jiný dosažitelný server se schématem
 * `mk_factory_test` by tedy prošel a dostal `migrate:fresh`.
 *
 * Tady se ověřuje CELÝ kontrakt endpointu. Je čistý (rozhoduje se jen
 * z konfigurace), takže patří do rychlé sady a dá se projít vyčerpávajícím
 * způsobem — a hlavně: dokazuje, že se nesprávný endpoint odmítne BEZ
 * pokusu o spojení. Běh nad skutečným serverem ověřuje
 * `Tests\Concurrency\ConcurrencyDatabaseGuardTest`.
 */
class ConcurrencyDatabaseGuardEndpointTest extends TestCase
{
    private const array NO_OPT_IN = [
        'connection' => null,
        'host' => null,
        'port' => null,
        'database' => null,
        'confirm' => null,
    ];

    /**
     * Věrná kopie toho, co z `config/database.php` vznikne pro spojení
     * `mysql` s hodnotami z `phpunit.concurrency.xml`.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function connectionConfig(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'mysql',
            'url' => null,
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'mk_factory_test',
            'username' => 'root',
            'password' => '',
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'prefix' => '',
            'name' => 'mysql',
        ], $overrides);
    }

    /**
     * Tatáž cesta, jakou jde konfigurace uvnitř
     * `DatabaseManager::configuration()` — tedy VÝSLEDNÁ runtime hodnota
     * po aplikaci DB_URL.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function runtime(array $config): array
    {
        return (new ConfigurationUrlParser)->parseConfiguration($config);
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     * @param  array<string, mixed>|null  $optIn
     */
    private function assertRejected(
        string $connectionName,
        array $runtimeConfig,
        ?array $optIn,
        string $expectedInMessage,
    ): void {
        try {
            ConcurrencyDatabaseGuard::assertEndpointAllowed($connectionName, $runtimeConfig, $optIn);
            $this->fail('Endpoint musí být odmítnut.');
        } catch (UnsafeConcurrencyDatabase $e) {
            $this->assertStringContainsString($expectedInMessage, $e->getMessage());
        }
    }

    // ---------- povolený endpoint ----------

    public function test_the_project_endpoint_passes(): void
    {
        ConcurrencyDatabaseGuard::assertEndpointAllowed(
            'mysql',
            self::runtime(self::connectionConfig()),
            self::NO_OPT_IN,
        );

        $this->addToAssertionCount(1);
    }

    // ---------- jednotlivé složky identity ----------

    public function test_a_different_port_is_rejected(): void
    {
        $this->assertRejected(
            'mysql',
            self::runtime(self::connectionConfig(['port' => '1'])),
            self::NO_OPT_IN,
            'port: očekáváno "3306", nalezeno "1"',
        );
    }

    public function test_a_different_host_is_rejected(): void
    {
        $this->assertRejected(
            'mysql',
            self::runtime(self::connectionConfig(['host' => '10.0.0.5'])),
            self::NO_OPT_IN,
            'host: očekáváno "127.0.0.1", nalezeno "10.0.0.5"',
        );
    }

    public function test_a_different_connection_name_is_rejected(): void
    {
        $this->assertRejected(
            'mariadb',
            self::runtime(self::connectionConfig(['driver' => 'mariadb', 'name' => 'mariadb'])),
            self::NO_OPT_IN,
            'connection: očekáváno "mysql", nalezeno "mariadb"',
        );
    }

    public function test_a_different_database_is_rejected(): void
    {
        $this->assertRejected(
            'mysql',
            self::runtime(self::connectionConfig(['database' => 'mkf_ci_test'])),
            self::NO_OPT_IN,
            'database: očekáváno "mk_factory_test", nalezeno "mkf_ci_test"',
        );
    }

    public function test_a_non_mysql_driver_is_rejected(): void
    {
        $this->assertRejected(
            'sqlite',
            ['driver' => 'sqlite', 'database' => ':memory:'],
            self::NO_OPT_IN,
            'sqlite',
        );
    }

    /**
     * Socket obchází host i port — endpoint by pak nešel ověřit.
     */
    public function test_a_unix_socket_connection_is_rejected(): void
    {
        $this->assertRejected(
            'mysql',
            self::runtime(self::connectionConfig(['unix_socket' => '/tmp/mysql.sock'])),
            self::NO_OPT_IN,
            '/tmp/mysql.sock',
        );
    }

    public function test_a_read_write_split_is_rejected(): void
    {
        $this->assertRejected(
            'mysql',
            self::runtime(self::connectionConfig(['read' => ['host' => '10.0.0.5']])),
            self::NO_OPT_IN,
            'read',
        );
    }

    // ---------- DB_URL ----------

    /**
     * `DB_URL` přepíše host, port i databázi, aniž by se `DB_HOST`
     * nebo `DB_PORT` změnily. Guard proto musí číst VÝSLEDNOU konfiguraci,
     * ne jednotlivé proměnné prostředí.
     */
    public function test_a_database_url_pointing_at_another_host_is_rejected(): void
    {
        $runtime = self::runtime(self::connectionConfig([
            'url' => 'mysql://root@10.0.0.5:3306/mk_factory_test',
        ]));

        $this->assertSame('10.0.0.5', $runtime['host'], 'Kontrola předpokladu: DB_URL host skutečně přepíše.');

        $this->assertRejected('mysql', $runtime, self::NO_OPT_IN, 'host: očekáváno "127.0.0.1", nalezeno "10.0.0.5"');
    }

    public function test_a_database_url_pointing_at_another_port_is_rejected(): void
    {
        $runtime = self::runtime(self::connectionConfig([
            'url' => 'mysql://root@127.0.0.1:1/mk_factory_test',
        ]));

        $this->assertSame(1, $runtime['port'], 'Kontrola předpokladu: DB_URL port skutečně přepíše.');

        $this->assertRejected('mysql', $runtime, self::NO_OPT_IN, 'port: očekáváno "3306", nalezeno "1"');
    }

    public function test_a_database_url_pointing_at_the_development_database_is_rejected(): void
    {
        $runtime = self::runtime(self::connectionConfig([
            'url' => 'mysql://root@127.0.0.1:3306/mk_factory',
        ]));

        $this->assertRejected('mysql', $runtime, self::NO_OPT_IN, 'mk_factory');
    }

    // ---------- opt-in ----------

    public function test_an_alternative_endpoint_without_an_opt_in_is_rejected(): void
    {
        $this->assertRejected(
            'mysql',
            self::runtime(self::connectionConfig(['host' => '10.0.0.5', 'database' => 'mkf_ci_test'])),
            self::NO_OPT_IN,
            'Souběžná sada smí destruktivně pracovat jen s endpointem',
        );
    }

    public function test_a_partial_opt_in_does_not_fall_back_to_the_default_endpoint(): void
    {
        $this->assertRejected(
            'mysql',
            self::runtime(self::connectionConfig(['host' => '10.0.0.5', 'database' => 'mkf_ci_test'])),
            [
                'connection' => null,
                'host' => '10.0.0.5',
                'port' => null,
                'database' => 'mkf_ci_test',
                'confirm' => ConcurrencyDatabaseGuard::CONFIRMATION,
            ],
            'MKF_CONCURRENCY_CONNECTION',
        );
    }

    public function test_a_complete_opt_in_allows_the_declared_endpoint(): void
    {
        ConcurrencyDatabaseGuard::assertEndpointAllowed(
            'mysql',
            self::runtime(self::connectionConfig(['host' => '10.0.0.5', 'port' => '3307', 'database' => 'mkf_ci_test'])),
            [
                'connection' => 'mysql',
                'host' => '10.0.0.5',
                'port' => '3307',
                'database' => 'mkf_ci_test',
                'confirm' => ConcurrencyDatabaseGuard::CONFIRMATION,
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Opt-in povoluje JEDEN endpoint, ne třídu endpointů — potvrzený host
     * nesmí propustit jiný port ani jinou databázi.
     */
    public function test_an_opt_in_does_not_widen_to_neighbouring_endpoints(): void
    {
        $optIn = [
            'connection' => 'mysql',
            'host' => '10.0.0.5',
            'port' => '3307',
            'database' => 'mkf_ci_test',
            'confirm' => ConcurrencyDatabaseGuard::CONFIRMATION,
        ];

        $this->assertRejected(
            'mysql',
            self::runtime(self::connectionConfig(['host' => '10.0.0.5', 'port' => '3306', 'database' => 'mkf_ci_test'])),
            $optIn,
            'port: očekáváno "3307", nalezeno "3306"',
        );

        $this->assertRejected(
            'mysql',
            self::runtime(self::connectionConfig(['host' => '10.0.0.5', 'port' => '3307', 'database' => 'mkf_jiny_test'])),
            $optIn,
            'database: očekáváno "mkf_ci_test", nalezeno "mkf_jiny_test"',
        );
    }
}
