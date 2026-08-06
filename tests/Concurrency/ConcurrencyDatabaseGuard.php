<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Illuminate\Database\Connection;

/**
 * Bezpečnostní závora před destruktivními operacemi souběžné sady.
 *
 * Sada volá `migrate:fresh`, což ZAHODÍ celé schéma. Dřív stačilo, že
 * driver je mysql — jenže `DB_DATABASE` z phpunit.xml lze přepsat proměnnou
 * shellu nebo CI, takže `migrate:fresh` mohl dopadnout na úplně jinou
 * databázi. XML `force="true"` je první obrana, tahle třída je druhá:
 * XML je konfigurace, ne bezpečnostní hranice.
 *
 * Kontrakt (vše musí platit, jinak fail closed):
 *   1. driver je `mysql`,
 *   2. jméno databáze v konfiguraci spojení je známé,
 *   3. `SELECT DATABASE()` vrací TOTÉŽ jméno (žádné pozdější `USE jiná_db`),
 *   4. jméno je přesně `mk_factory_test`, nebo prošlo výslovným opt-inem,
 *   5. jméno není na denylistu běžných vývojových a systémových databází.
 *
 * Opt-in pro jiné testovací jméno vyžaduje SOUČASNĚ:
 *   - `MKF_CONCURRENCY_DATABASE` shodné s aktivní databází,
 *   - `MKF_CONCURRENCY_DATABASE_CONFIRM` = hodnota konstanty CONFIRMATION,
 *   - jméno ve tvaru `[a-z0-9_]+_test` (samotné „obsahuje test“ nestačí),
 *   - jméno mimo denylist.
 */
final class ConcurrencyDatabaseGuard
{
    public const string DEFAULT_DATABASE = 'mk_factory_test';

    public const string CONFIRMATION = 'ano-smaz-tuto-databazi';

    /**
     * `mariadb` je v config/database.php samostatné spojení se stejnou
     * sémantikou zámků — vynechat ho by znamenalo tiché odmítnutí sady
     * u legitimní konfigurace.
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
     * @param  array{database?: ?string, confirm?: ?string}|null  $optIn  null = číst z prostředí
     *
     * @throws UnsafeConcurrencyDatabase
     */
    public static function assertSafe(Connection $connection, ?array $optIn = null): void
    {
        $driver = $connection->getDriverName();

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw UnsafeConcurrencyDatabase::forDriver($driver);
        }

        $configured = (string) $connection->getDatabaseName();

        if (trim($configured) === '') {
            throw UnsafeConcurrencyDatabase::forMissingName();
        }

        // Skutečně aktivní schéma spojení — konfigurace sama nestačí, po
        // `USE jiná_db` by ukazovaly každá jinam.
        $active = $connection->selectOne('SELECT DATABASE() AS db')->db ?? null;

        if ($active === null || trim((string) $active) === '') {
            throw UnsafeConcurrencyDatabase::forMissingName();
        }

        if ($configured !== (string) $active) {
            throw UnsafeConcurrencyDatabase::forMismatch($configured, (string) $active);
        }

        self::assertNameAllowed($configured, $optIn);
    }

    /**
     * Čistá část kontraktu (bez spojení) — testovatelná bez databáze.
     *
     * @param  array{database?: ?string, confirm?: ?string}|null  $optIn
     *
     * @throws UnsafeConcurrencyDatabase
     */
    public static function assertNameAllowed(string $name, ?array $optIn = null): void
    {
        if (! self::isNameAllowed($name, $optIn)) {
            throw UnsafeConcurrencyDatabase::forDisallowedName($name);
        }
    }

    /**
     * @param  array{database?: ?string, confirm?: ?string}|null  $optIn
     */
    public static function isNameAllowed(string $name, ?array $optIn = null): bool
    {
        if (in_array($name, self::DENYLIST, true)) {
            return false;
        }

        if ($name === self::DEFAULT_DATABASE) {
            return true;
        }

        $optIn ??= [
            'database' => self::environment('MKF_CONCURRENCY_DATABASE'),
            'confirm' => self::environment('MKF_CONCURRENCY_DATABASE_CONFIRM'),
        ];

        $optInDatabase = $optIn['database'] ?? null;
        $confirmation = $optIn['confirm'] ?? null;

        return $optInDatabase === $name
            && $confirmation === self::CONFIRMATION
            && preg_match(self::SAFE_NAME_PATTERN, $name) === 1;
    }

    private static function environment(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
