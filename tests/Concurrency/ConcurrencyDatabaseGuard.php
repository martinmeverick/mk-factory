<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Illuminate\Database\Connection;

/**
 * Bezpečnostní závora před destruktivními operacemi souběžné sady.
 *
 * Sada volá `migrate:fresh`, což ZAHODÍ celé schéma. XML `force="true"` je
 * první obrana, tahle třída je druhá: XML je konfigurace, ne bezpečnostní
 * hranice (PHPUnit u `<env>` zapisuje `putenv()` a `$_ENV`, ale ne
 * `$_SERVER`, a Laravel čte `$_SERVER` dřív — exportovaná proměnná se tedy
 * k aplikaci dostane i s `force`).
 *
 * ## Co se kontroluje
 *
 * Dřív se ověřoval jen driver a jméno schématu. To NESTAČÍ: schéma
 * `mk_factory_test` může existovat na libovolném serveru. Re-review to
 * potvrdilo — `DB_PORT=1` přebilo hodnotu z XML i přes `force="true"`
 * a Laravel se skutečně pokusil připojit na port 1. Jiný dosažitelný
 * server se stejně pojmenovaným schématem by tedy prošel a dostal
 * `migrate:fresh`.
 *
 * Guard proto ověřuje CELOU očekávanou identitu endpointu (vše musí
 * platit, jinak fail closed):
 *
 *   1. driver je `mysql` nebo `mariadb`,
 *   2. spojení nemá read/write rozdělení (obešlo by body 4–7),
 *   3. spojení nepoužívá unix socket (ten obchází host i port),
 *   4. jméno spojení je přesně to očekávané,
 *   5. host je přesně ten očekávaný,
 *   6. port je přesně ten očekávaný,
 *   7. jméno databáze je přesně to očekávané a není na denylistu,
 *   8. a TEPRVE POTOM: `SELECT DATABASE()` vrací totéž jméno jako
 *      konfigurace (žádné pozdější `USE jiná_db`).
 *
 * Body 1–7 se rozhodují VÝHRADNĚ z konfigurace, takže se nesprávný
 * endpoint odmítne ještě PŘED jakýmkoli pokusem o spojení. Jinak by
 * „špatný port" skončil síťovou chybou místo řízeného odmítnutí.
 *
 * ## Runtime konfigurace, ne getenv()
 *
 * Čte se VÝSLEDNÁ konfigurace spojení, tedy stav PO aplikaci `DB_URL`
 * (`Illuminate\Support\ConfigurationUrlParser` běží uvnitř
 * `DatabaseManager::configuration()`). Samotné `getenv('DB_HOST')` by
 * nestačilo: `DB_URL=mysql://root@cizi-server:3306/mk_factory_test`
 * přepíše host, port i databázi, aniž by se `DB_HOST`/`DB_PORT` změnily.
 *
 * ## Výchozí povolený endpoint
 *
 * Odvozený z `phpunit.concurrency.xml`, tedy z toho, co projekt skutečně
 * používá: spojení `mysql` na `127.0.0.1:3306`, databáze `mk_factory_test`.
 * Žádná široká pravidla („jakýkoli localhost", „jakýkoli port", „cokoli
 * končící na _test") — endpoint se povoluje jmenovitě.
 *
 * ## Opt-in pro jiný endpoint
 *
 * Musí být ÚPLNÝ a výslovný — všechny čtyři složky endpointu plus
 * potvrzení, že jde o destruktivní testovací cíl:
 *
 *   MKF_CONCURRENCY_CONNECTION, MKF_CONCURRENCY_HOST,
 *   MKF_CONCURRENCY_PORT, MKF_CONCURRENCY_DATABASE,
 *   MKF_CONCURRENCY_DATABASE_CONFIRM=<CONFIRMATION>
 *
 * Navíc musí jméno databáze KONČIT na `_test` (samotné „obsahuje test"
 * nestačí) a nesmí být na denylistu — ten platí i s opt-inem. Neúplný
 * opt-in se nedegraduje na výchozí endpoint, ale rovnou odmítne.
 */
final class ConcurrencyDatabaseGuard
{
    public const string DEFAULT_CONNECTION = 'mysql';

    public const string DEFAULT_HOST = '127.0.0.1';

    public const string DEFAULT_PORT = '3306';

    public const string DEFAULT_DATABASE = 'mk_factory_test';

    public const string CONFIRMATION = 'ano-smaz-tuto-databazi';

    /**
     * `mariadb` je v config/database.php samostatné spojení se stejnou
     * sémantikou zámků. Zůstává podporované, ale běh přes ně je jiný
     * endpoint než výchozí — vyžaduje tedy opt-in jako každý jiný.
     */
    private const array SUPPORTED_DRIVERS = ['mysql', 'mariadb'];

    /**
     * Jména, která neprojdou ani s opt-inem. `mk_factory` je vývojová
     * databáze projektu — právě tu by omyl v prostředí zahodil.
     */
    private const array DENYLIST = [
        'mk_factory',
        'mysql',
        'information_schema',
        'performance_schema',
        'sys',
        'phpmyadmin',
        'test',
    ];

    private const string SAFE_NAME_PATTERN = '/^[a-z0-9_]{1,58}_test$/';

    /**
     * Složky opt-inu; všechny povinné, jakmile je opt-in vůbec naznačený.
     */
    private const array OPT_IN_KEYS = ['connection', 'host', 'port', 'database', 'confirm'];

    private const array OPT_IN_VARIABLES = [
        'connection' => 'MKF_CONCURRENCY_CONNECTION',
        'host' => 'MKF_CONCURRENCY_HOST',
        'port' => 'MKF_CONCURRENCY_PORT',
        'database' => 'MKF_CONCURRENCY_DATABASE',
        'confirm' => 'MKF_CONCURRENCY_DATABASE_CONFIRM',
    ];

    /**
     * @param  array<string, mixed>|null  $optIn  null = číst z prostředí
     *
     * @throws UnsafeConcurrencyDatabase
     */
    public static function assertSafe(Connection $connection, ?array $optIn = null): void
    {
        // Endpoint se rozhodne z konfigurace — dřív, než se sáhne na síť.
        self::assertEndpointAllowed(
            (string) $connection->getName(),
            (array) $connection->getConfig(),
            $optIn,
        );

        $configured = (string) $connection->getDatabaseName();

        // Skutečně aktivní schéma spojení — konfigurace sama nestačí, po
        // `USE jiná_db` by ukazovaly každá jinam.
        $active = $connection->selectOne('SELECT DATABASE() AS db')->db ?? null;

        if ($active === null || trim((string) $active) === '') {
            throw UnsafeConcurrencyDatabase::forMissingName();
        }

        if ($configured !== (string) $active) {
            throw UnsafeConcurrencyDatabase::forMismatch($configured, (string) $active);
        }
    }

    /**
     * Čistá část kontraktu (bez spojení): celý endpoint podle VÝSLEDNÉ
     * konfigurace. Testovatelné vyčerpávajícím způsobem a bez databáze.
     *
     * @param  array<string, mixed>  $runtimeConfig  konfigurace spojení PO aplikaci DB_URL
     * @param  array<string, mixed>|null  $optIn
     *
     * @throws UnsafeConcurrencyDatabase
     */
    public static function assertEndpointAllowed(
        string $connectionName,
        array $runtimeConfig,
        ?array $optIn = null,
    ): void {
        $driver = self::stringValue($runtimeConfig, 'driver');

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw UnsafeConcurrencyDatabase::forDriver($driver);
        }

        foreach (['read', 'write'] as $split) {
            if (isset($runtimeConfig[$split])) {
                throw UnsafeConcurrencyDatabase::forSplitConnection($split);
            }
        }

        $socket = self::stringValue($runtimeConfig, 'unix_socket');

        if ($socket !== '') {
            throw UnsafeConcurrencyDatabase::forUnixSocket($socket);
        }

        $actual = [
            'connection' => $connectionName,
            'host' => self::stringValue($runtimeConfig, 'host'),
            'port' => self::stringValue($runtimeConfig, 'port'),
            'database' => self::stringValue($runtimeConfig, 'database'),
        ];

        if ($actual['database'] === '') {
            throw UnsafeConcurrencyDatabase::forMissingName();
        }

        // Denylist platí vždycky — i proti opt-inu.
        if (in_array($actual['database'], self::DENYLIST, true)) {
            throw UnsafeConcurrencyDatabase::forDisallowedName($actual['database']);
        }

        $expected = self::expectedEndpoint($optIn);

        if ($actual !== $expected) {
            throw UnsafeConcurrencyDatabase::forEndpoint($expected, $actual);
        }
    }

    /**
     * Jediný povolený endpoint: výchozí, nebo ten z úplného opt-inu.
     *
     * @param  array<string, mixed>|null  $optIn
     * @return array{connection: string, host: string, port: string, database: string}
     *
     * @throws UnsafeConcurrencyDatabase
     */
    public static function expectedEndpoint(?array $optIn = null): array
    {
        $optIn ??= self::environmentOptIn();

        $provided = [];

        foreach (self::OPT_IN_KEYS as $key) {
            $value = $optIn[$key] ?? null;

            if ($value !== null && (string) $value !== '') {
                $provided[$key] = (string) $value;
            }
        }

        if ($provided === []) {
            return [
                'connection' => self::DEFAULT_CONNECTION,
                'host' => self::DEFAULT_HOST,
                'port' => self::DEFAULT_PORT,
                'database' => self::DEFAULT_DATABASE,
            ];
        }

        // Naznačený opt-in se NEDEGRADUJE na výchozí endpoint: půlka
        // potvrzení není potvrzení.
        $missing = array_values(array_diff(self::OPT_IN_KEYS, array_keys($provided)));

        if ($missing !== []) {
            throw UnsafeConcurrencyDatabase::forIncompleteOptIn($missing, self::OPT_IN_VARIABLES);
        }

        if ($provided['confirm'] !== self::CONFIRMATION) {
            throw UnsafeConcurrencyDatabase::forUnconfirmedOptIn();
        }

        if (! self::isDatabaseNameOptInnable($provided['database'])) {
            throw UnsafeConcurrencyDatabase::forDisallowedName($provided['database']);
        }

        return [
            'connection' => $provided['connection'],
            'host' => $provided['host'],
            'port' => $provided['port'],
            'database' => $provided['database'],
        ];
    }

    /**
     * Smí se tohle jméno databáze vůbec objevit v opt-inu? Denylist má
     * přednost před vším; jinak musí jméno KONČIT na `_test`.
     */
    public static function isDatabaseNameOptInnable(string $name): bool
    {
        if (in_array($name, self::DENYLIST, true)) {
            return false;
        }

        return preg_match(self::SAFE_NAME_PATTERN, $name) === 1;
    }

    /**
     * @return array<string, string|null>
     */
    private static function environmentOptIn(): array
    {
        $optIn = [];

        foreach (self::OPT_IN_VARIABLES as $key => $variable) {
            $optIn[$key] = self::environment($variable);
        }

        return $optIn;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function stringValue(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function environment(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
