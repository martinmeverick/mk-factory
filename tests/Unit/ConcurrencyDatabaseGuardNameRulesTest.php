<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Concurrency\ConcurrencyDatabaseGuard;
use Tests\Concurrency\UnsafeConcurrencyDatabase;

/**
 * RE-REVIEW, nález 1: pravidla pro JMÉNO databáze, nad kterou smí souběžná
 * sada spustit `migrate:fresh`.
 *
 * Čistá část kontraktu (bez spojení), aby šla ověřit vyčerpávajícím
 * způsobem a v rychlé sadě. Celý endpoint (spojení, host, port) řeší
 * `ConcurrencyDatabaseGuardEndpointTest`, běh nad skutečným serverem
 * `Tests\Concurrency\ConcurrencyDatabaseGuardTest`.
 *
 * Opt-in se do všech volání předává VÝSLOVNĚ — testy nesmí záviset na
 * proměnných prostředí toho, kdo je spouští.
 */
class ConcurrencyDatabaseGuardNameRulesTest extends TestCase
{
    private const array NO_OPT_IN = [
        'connection' => null,
        'host' => null,
        'port' => null,
        'database' => null,
        'confirm' => null,
    ];

    /**
     * @return array<string, string>
     */
    private static function optInFor(string $database): array
    {
        return [
            'connection' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => $database,
            'confirm' => ConcurrencyDatabaseGuard::CONFIRMATION,
        ];
    }

    public function test_without_an_opt_in_the_expected_endpoint_is_the_project_default(): void
    {
        $this->assertSame(
            [
                'connection' => ConcurrencyDatabaseGuard::DEFAULT_CONNECTION,
                'host' => ConcurrencyDatabaseGuard::DEFAULT_HOST,
                'port' => ConcurrencyDatabaseGuard::DEFAULT_PORT,
                'database' => ConcurrencyDatabaseGuard::DEFAULT_DATABASE,
            ],
            ConcurrencyDatabaseGuard::expectedEndpoint(self::NO_OPT_IN),
        );
    }

    /**
     * Denylist má přednost i před platným opt-inem — vývojovou ani
     * systémovou databázi nejde povolit vůbec nijak.
     */
    public function test_denylisted_names_are_rejected_even_with_a_valid_opt_in(): void
    {
        foreach (['mk_factory', 'mysql', 'information_schema', 'performance_schema', 'sys', 'phpmyadmin', 'test'] as $name) {
            $this->assertFalse(
                ConcurrencyDatabaseGuard::isDatabaseNameOptInnable($name),
                "Databáze {$name} nesmí projít ani s opt-inem.",
            );

            try {
                ConcurrencyDatabaseGuard::expectedEndpoint(self::optInFor($name));
                $this->fail("Databáze {$name} nesmí projít ani s opt-inem.");
            } catch (UnsafeConcurrencyDatabase $e) {
                $this->assertStringContainsString($name, $e->getMessage());
            }
        }
    }

    /**
     * „Obsahuje test" nestačí — jméno musí na `_test` KONČIT. Jinak by
     * prošla i `testovaci_produkce` nebo `mk_factory_testovaci_kopie`.
     */
    public function test_name_must_end_with_the_test_suffix(): void
    {
        foreach (['testovaci_produkce', 'mk_factory_testovaci_kopie', 'test_mk_factory', 'mkf_testing'] as $name) {
            $this->assertFalse(
                ConcurrencyDatabaseGuard::isDatabaseNameOptInnable($name),
                "Jméno {$name} nekončí na _test a nesmí projít.",
            );
        }

        $this->assertTrue(ConcurrencyDatabaseGuard::isDatabaseNameOptInnable('mkf_ci_5_test'));
    }

    public function test_names_with_unexpected_characters_are_rejected(): void
    {
        foreach (['mk factory_test', 'mk-factory_test', 'MK_FACTORY_TEST', 'mk`factory_test', "mk\nfactory_test"] as $name) {
            $this->assertFalse(
                ConcurrencyDatabaseGuard::isDatabaseNameOptInnable($name),
                'Jméno '.var_export($name, true).' nesmí projít.',
            );
        }
    }

    public function test_a_confirmed_opt_in_promotes_a_dedicated_test_schema(): void
    {
        $this->assertSame(
            [
                'connection' => 'mysql',
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => 'mkf_ci_test',
            ],
            ConcurrencyDatabaseGuard::expectedEndpoint(self::optInFor('mkf_ci_test')),
        );
    }

    public function test_an_opt_in_without_confirmation_is_rejected(): void
    {
        try {
            ConcurrencyDatabaseGuard::expectedEndpoint(
                ['confirm' => 'ano'] + self::optInFor('mkf_ci_test'),
            );
            $this->fail('Opt-in se špatným potvrzením musí být odmítnut.');
        } catch (UnsafeConcurrencyDatabase $e) {
            $this->assertStringContainsString('MKF_CONCURRENCY_DATABASE_CONFIRM', $e->getMessage());
        }
    }
}
