<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Bezpečnostní závora hlavní testovací sady.
 *
 * Sada jede přes `RefreshDatabase`, tedy spouští `migrate:fresh` —
 * destruktivní operaci. Smí proto běžet výhradně nad SQLite in-memory
 * databází, která zaniká s procesem.
 *
 * ## Co se kontroluje (vše musí platit, jinak fail closed)
 *
 *  1. jméno aktivního spojení je přesně `sqlite`,
 *  2. driver výsledné konfigurace je `sqlite`,
 *  3. databáze je přesně `:memory:`,
 *  4. na spojení není aktivní `DB_URL` (ten by celou konfiguraci přesměroval),
 *  5. spojení nemá read/write rozdělení (to by obcházelo body 2–3).
 *
 * Kontroluje se VÝSLEDNÁ runtime konfigurace spojení, tedy stav PO
 * aplikaci `DB_URL` (`Illuminate\Support\ConfigurationUrlParser` běží
 * uvnitř `DatabaseManager::configuration()`). Samotné `getenv()` by
 * nestačilo: `DB_URL=mysql://…` přepíše driver, host, port i jméno
 * databáze, aniž by se `DB_CONNECTION` nebo `DB_DATABASE` změnily.
 * Naopak `DB_HOST`/`DB_PORT` SQLite konfigurace ignoruje — proto se
 * vypisují jen do hlášky jako diagnostika, rozhoduje výsledná konfigurace.
 *
 * ## Žádný opt-in
 *
 * Alternativní persistentní testovací databáze pro hlavní sadu VĚDOMĚ
 * neexistuje — projekt ji nepotřebuje a každá taková cesta by byla další
 * způsob, jak `migrate:fresh` nasměrovat jinam. Destruktivní sada nad
 * skutečným serverem je jenom jedna (souběžná) a ta má vlastní, přísný
 * opt-in ve `Tests\Concurrency\ConcurrencyDatabaseGuard`.
 *
 * ## Kdy se spouští
 *
 * Volá se z `Tests\TestCase::refreshApplication()`, tedy hned po
 * bootstrapu aplikace a PŘED `setUpTraits()` — a tím před
 * `RefreshDatabase::refreshDatabase()` → `migrate:fresh`. Podrobné
 * odůvodnění (a proč NEJDE použít `beforeRefreshingDatabase()`) je
 * u volajícího.
 */
final class PrimaryTestDatabaseGuard
{
    public const string EXPECTED_CONNECTION = 'sqlite';

    public const string EXPECTED_DRIVER = 'sqlite';

    public const string EXPECTED_DATABASE = ':memory:';

    /**
     * Vstupy prostředí, které o cíli spojení rozhodují. Vypisují se do
     * chybové hlášky, aby bylo hned vidět, odkud přesměrování přišlo.
     */
    private const array REPORTED_ENVIRONMENT = [
        'DB_URL',
        'DB_CONNECTION',
        'DB_DATABASE',
        'DB_HOST',
        'DB_PORT',
    ];

    /**
     * Závora nad živou aplikací. NEOTEVÍRÁ PDO — `DatabaseManager`
     * sestaví spojení líně, takže se jen čte hotová konfigurace.
     *
     * @throws UnsafePrimaryTestDatabase
     */
    public static function assertDisposable(DatabaseManager $database, ConfigRepository $config): void
    {
        $name = (string) $config->get('database.default');

        /** @var Connection $connection */
        $connection = $database->connection($name);

        self::assertRuntimeConfiguration(
            (string) $connection->getName(),
            (array) $connection->getConfig(),
            $config->get('database.connections.'.$name.'.url'),
        );
    }

    /**
     * Čistá část kontraktu: rozhoduje se jen podle výsledné konfigurace,
     * takže jde ověřit vyčerpávajícím způsobem bez databáze i bez aplikace.
     *
     * @param  array<string, mixed>  $runtimeConfig  konfigurace spojení PO aplikaci DB_URL
     * @param  mixed  $configuredUrl  syrová hodnota `database.connections.<name>.url` (tedy DB_URL)
     *
     * @throws UnsafePrimaryTestDatabase
     */
    public static function assertRuntimeConfiguration(
        string $connectionName,
        array $runtimeConfig,
        mixed $configuredUrl = null,
    ): void {
        $environment = self::environmentSnapshot();

        if ($connectionName !== self::EXPECTED_CONNECTION) {
            throw UnsafePrimaryTestDatabase::because(
                sprintf('Aktivní spojení je "%s".', $connectionName),
                $environment,
            );
        }

        if (is_string($configuredUrl) && trim($configuredUrl) !== '') {
            // ConfigurationUrlParser klíč `url` z výsledné konfigurace
            // odstraní — kdyby se četla jen ta, přesměrování by nebylo
            // vidět jako příčina, jen jako následek.
            throw UnsafePrimaryTestDatabase::because(
                'Na spojení je aktivní DB_URL, které konfiguraci přesměrovává.',
                $environment,
            );
        }

        foreach (['read', 'write'] as $split) {
            if (isset($runtimeConfig[$split])) {
                throw UnsafePrimaryTestDatabase::because(
                    sprintf('Spojení má oddělenou konfiguraci "%s", ta by kontrolu obešla.', $split),
                    $environment,
                );
            }
        }

        $driver = is_scalar($runtimeConfig['driver'] ?? null) ? (string) $runtimeConfig['driver'] : '';

        if ($driver !== self::EXPECTED_DRIVER) {
            throw UnsafePrimaryTestDatabase::because(
                sprintf('Výsledný driver spojení je "%s".', $driver === '' ? '(neznámý)' : $driver),
                $environment,
            );
        }

        $database = is_scalar($runtimeConfig['database'] ?? null) ? (string) $runtimeConfig['database'] : '';

        if ($database !== self::EXPECTED_DATABASE) {
            throw UnsafePrimaryTestDatabase::because(
                sprintf('Výsledná databáze spojení je "%s".', $database === '' ? '(neznámá)' : $database),
                $environment,
            );
        }
    }

    /**
     * @return array<string, string|null>
     */
    public static function environmentSnapshot(): array
    {
        $snapshot = [];

        foreach (self::REPORTED_ENVIRONMENT as $key) {
            $snapshot[$key] = self::environment($key);
        }

        return $snapshot;
    }

    private static function environment(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
