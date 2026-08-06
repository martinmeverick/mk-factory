<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Concurrency\ConcurrencyDatabaseGuard;
use Tests\Concurrency\UnsafeConcurrencyDatabase;

/**
 * RE-REVIEW, nález 1: pravidla pro jméno databáze, nad kterou smí souběžná
 * sada spustit `migrate:fresh`.
 *
 * Čistá část kontraktu (bez spojení), aby šla ověřit vyčerpávajícím
 * způsobem a v rychlé sadě. Zbytek (driver, config vs. SELECT DATABASE())
 * ověřuje Tests\Concurrency\ConcurrencyDatabaseGuardTest nad MariaDB.
 */
class ConcurrencyDatabaseGuardNameRulesTest extends TestCase
{
    private const array CONFIRMED = ['database' => null, 'confirm' => ConcurrencyDatabaseGuard::CONFIRMATION];

    public function test_default_test_database_is_allowed(): void
    {
        $this->assertTrue(ConcurrencyDatabaseGuard::isNameAllowed('mk_factory_test', ['database' => null, 'confirm' => null]));
    }

    public function test_development_database_is_rejected(): void
    {
        $this->assertFalse(ConcurrencyDatabaseGuard::isNameAllowed('mk_factory', ['database' => null, 'confirm' => null]));
    }

    /**
     * Denylist má přednost i před platným opt-inem — vývojovou ani
     * systémovou databázi nejde povolit vůbec nijak.
     */
    public function test_denylisted_names_are_rejected_even_with_a_valid_opt_in(): void
    {
        foreach (['mk_factory', 'mysql', 'information_schema', 'performance_schema', 'sys', 'phpmyadmin', 'test'] as $name) {
            $this->assertFalse(
                ConcurrencyDatabaseGuard::isNameAllowed($name, ['database' => $name] + self::CONFIRMED),
                "Databáze {$name} nesmí projít ani s opt-inem.",
            );
        }
    }

    public function test_opt_in_requires_all_three_conditions(): void
    {
        $name = 'mkf_ci_test';

        // Bez opt-inu.
        $this->assertFalse(ConcurrencyDatabaseGuard::isNameAllowed($name, ['database' => null, 'confirm' => null]));

        // Jméno sedí, chybí potvrzení.
        $this->assertFalse(ConcurrencyDatabaseGuard::isNameAllowed($name, ['database' => $name, 'confirm' => null]));

        // Potvrzení je, ale opt-in ukazuje na jinou databázi.
        $this->assertFalse(ConcurrencyDatabaseGuard::isNameAllowed($name, ['database' => 'mkf_jina_test'] + self::CONFIRMED));

        // Špatná hodnota potvrzení.
        $this->assertFalse(ConcurrencyDatabaseGuard::isNameAllowed($name, ['database' => $name, 'confirm' => 'ano']));

        // Vše splněno.
        $this->assertTrue(ConcurrencyDatabaseGuard::isNameAllowed($name, ['database' => $name] + self::CONFIRMED));
    }

    /**
     * „Obsahuje test“ nestačí — jméno musí na `_test` KONČIT. Jinak by
     * prošla i `testovaci_produkce` nebo `mk_factory_testovaci_kopie`.
     */
    public function test_name_must_end_with_the_test_suffix(): void
    {
        foreach (['testovaci_produkce', 'mk_factory_testovaci_kopie', 'test_mk_factory', 'mkf_testing'] as $name) {
            $this->assertFalse(
                ConcurrencyDatabaseGuard::isNameAllowed($name, ['database' => $name] + self::CONFIRMED),
                "Jméno {$name} nekončí na _test a nesmí projít.",
            );
        }

        $this->assertTrue(
            ConcurrencyDatabaseGuard::isNameAllowed('mkf_ci_5_test', ['database' => 'mkf_ci_5_test'] + self::CONFIRMED),
        );
    }

    public function test_names_with_unexpected_characters_are_rejected(): void
    {
        foreach (['mk factory_test', 'mk-factory_test', 'MK_FACTORY_TEST', 'mk`factory_test', "mk\nfactory_test"] as $name) {
            $this->assertFalse(
                ConcurrencyDatabaseGuard::isNameAllowed($name, ['database' => $name] + self::CONFIRMED),
                'Jméno '.var_export($name, true).' nesmí projít.',
            );
        }
    }

    public function test_assert_name_allowed_throws_with_actionable_message(): void
    {
        try {
            ConcurrencyDatabaseGuard::assertNameAllowed('mk_factory', ['database' => null, 'confirm' => null]);
            $this->fail('Vývojová databáze musí být odmítnuta.');
        } catch (UnsafeConcurrencyDatabase $e) {
            $this->assertStringContainsString('mk_factory', $e->getMessage());
            $this->assertStringContainsString('MKF_CONCURRENCY_DATABASE', $e->getMessage());
        }
    }
}
