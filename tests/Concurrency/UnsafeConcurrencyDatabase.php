<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use RuntimeException;

/**
 * Souběžná sada odmítla sáhnout na databázi, která neprošla bezpečnostní
 * kontrolou. Fail closed: raději spadne celá sada, než aby `migrate:fresh`
 * zahodil schéma, které mu nepatří.
 */
final class UnsafeConcurrencyDatabase extends RuntimeException
{
    public static function forDriver(string $driver): self
    {
        return new self(sprintf(
            'Souběžná sada běží jen nad MariaDB/MySQL, aktivní driver je "%s". '
            .'Spusťte ji přes `composer test:concurrency`.',
            $driver === '' ? '(neznámý)' : $driver,
        ));
    }

    public static function forMissingName(): self
    {
        return new self(
            'Nepodařilo se zjistit jméno aktivní databáze — bez něj se destruktivní operace nespustí.'
        );
    }

    public static function forMismatch(string $configured, string $active): self
    {
        return new self(sprintf(
            'Konfigurace spojení ukazuje na databázi "%s", ale SELECT DATABASE() vrací "%s". '
            .'Dokud se neshodují, souběžná sada nesmí spustit migrate:fresh.',
            $configured,
            $active,
        ));
    }

    /**
     * Nesouhlasí endpoint jako celek. Rozhoduje se z KONFIGURACE, tedy
     * ještě před pokusem o spojení — „špatný port" proto skončí tady,
     * ne síťovou chybou.
     *
     * @param  array{connection: string, host: string, port: string, database: string}  $expected
     * @param  array{connection: string, host: string, port: string, database: string}  $actual
     */
    public static function forEndpoint(array $expected, array $actual): self
    {
        $differences = [];

        foreach ($expected as $key => $value) {
            if (($actual[$key] ?? '') !== $value) {
                $differences[] = sprintf('%s: očekáváno "%s", nalezeno "%s"', $key, $value, $actual[$key] ?? '');
            }
        }

        return new self(sprintf(
            'Souběžná sada smí destruktivně pracovat jen s endpointem %s. Nalezeno %s. Neshoda — %s. '
            .'Endpoint se ověřuje z VÝSLEDNÉ konfigurace spojení (tedy i po aplikaci DB_URL), '
            .'protože stejně pojmenované schéma může existovat na jiném serveru. '
            .'Jiný endpoint vyžaduje úplný opt-in: %s.',
            self::describe($expected),
            self::describe($actual),
            implode('; ', $differences),
            self::optInHint(),
        ));
    }

    public static function forSplitConnection(string $split): self
    {
        return new self(sprintf(
            'Spojení má oddělenou konfiguraci "%s". Takové spojení může mířit na víc serverů najednou, '
            .'takže kontrolu endpointu obchází — souběžná sada ho nepovolí.',
            $split,
        ));
    }

    public static function forUnixSocket(string $socket): self
    {
        return new self(sprintf(
            'Spojení používá unix socket "%s", který obchází kontrolu hostu i portu. '
            .'Souběžná sada vyžaduje TCP endpoint, jehož identitu lze ověřit.',
            $socket,
        ));
    }

    /**
     * @param  list<string>  $missing
     * @param  array<string, string>  $variables
     */
    public static function forIncompleteOptIn(array $missing, array $variables): self
    {
        $names = array_map(static fn (string $key): string => $variables[$key] ?? $key, $missing);

        return new self(sprintf(
            'Opt-in na jiný testovací endpoint je neúplný — chybí %s. '
            .'Neúplný opt-in se NEDEGRADUJE na výchozí endpoint: destruktivní cíl se potvrzuje celý, '
            .'nebo vůbec. Vyžadováno: %s.',
            implode(', ', $names),
            self::optInHint(),
        ));
    }

    public static function forUnconfirmedOptIn(): self
    {
        return new self(sprintf(
            'Opt-in na jiný testovací endpoint není potvrzený. %s musí být přesně "%s" — '
            .'jde o výslovné potvrzení, že cílová databáze je určená ke smazání.',
            'MKF_CONCURRENCY_DATABASE_CONFIRM',
            ConcurrencyDatabaseGuard::CONFIRMATION,
        ));
    }

    public static function forDisallowedName(string $name): self
    {
        return new self(sprintf(
            'Databáze "%s" není povolená pro destruktivní souběžnou sadu. Povolena je výhradně "%s"; '
            .'jiné jméno musí KONČIT na `_test`, nesmí být na denylistu vývojových a systémových '
            .'databází a vyžaduje úplný opt-in: %s. Vývojovou ani produkční databázi povolit nejde.',
            $name,
            ConcurrencyDatabaseGuard::DEFAULT_DATABASE,
            self::optInHint(),
        ));
    }

    /**
     * @param  array{connection: string, host: string, port: string, database: string}  $endpoint
     */
    private static function describe(array $endpoint): string
    {
        return sprintf(
            'spojení "%s" na %s:%s, databáze "%s"',
            $endpoint['connection'],
            $endpoint['host'],
            $endpoint['port'],
            $endpoint['database'],
        );
    }

    private static function optInHint(): string
    {
        return 'MKF_CONCURRENCY_CONNECTION, MKF_CONCURRENCY_HOST, MKF_CONCURRENCY_PORT, '
            .'MKF_CONCURRENCY_DATABASE a MKF_CONCURRENCY_DATABASE_CONFIRM='
            .ConcurrencyDatabaseGuard::CONFIRMATION;
    }
}
