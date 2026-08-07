<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DestructiveMigrationProbeTest;

/**
 * CLOSURE REVIEW, nález 2 — část, která potřebuje SKUTEČNÝ persistentní
 * server.
 *
 * Původní reprodukce zněla: podstrčené `DB_URL` na dočasné MySQL schéma
 * → `composer test` v něm vyrobil 22 tabulek → a teprve pak sada spadla.
 * Tenhle test tu reprodukci opakuje jako regresi: v podstrčeném schématu
 * nesmí vzniknout ani zmizet jediná tabulka.
 *
 * Proč v souběžné sadě: hlavní sada je schválně bez závislosti na
 * databázovém serveru (SQLite in-memory) a nesmí ji získat kvůli jednomu
 * testu. Souběžná sada MariaDB stejně vyžaduje a už tu má precedens
 * (`ConcurrencyDatabaseGuardTest`). NEDĚDÍ z `ConcurrencyTestCase` —
 * ten v setUp() migruje.
 *
 * Pracuje výhradně nad dočasným izolovaným schématem, které si sám
 * vytvoří a v tearDown() zase zahodí.
 */
class PrimaryDatabaseGuardMysqlProbeTest extends BaseTestCase
{
    private const string PROBE_SCHEMA = 'mkf_primary_guard_probe';

    private ?string $workspace = null;

    private bool $schemaCreated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Kontrola závory hlavní sady nad MySQL vyžaduje MariaDB/MySQL.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->schemaCreated) {
            DB::statement('DROP DATABASE IF EXISTS `'.self::PROBE_SCHEMA.'`');
            $this->schemaCreated = false;
        }

        if ($this->workspace !== null) {
            foreach ((glob($this->workspace.'/*') ?: []) as $file) {
                @unlink($file);
            }

            @rmdir($this->workspace);
            $this->workspace = null;
        }

        DB::disconnect();

        parent::tearDown();
    }

    private function workspace(): string
    {
        if ($this->workspace === null) {
            $this->workspace = sys_get_temp_dir().'/mkf-primary-guard-mysql-'.bin2hex(random_bytes(8));
            mkdir($this->workspace, 0700, true);
        }

        return $this->workspace;
    }

    private function tableCount(string $schema): int
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ?',
            [$schema],
        )->c;
    }

    public function test_a_database_url_pointing_at_mysql_leaves_the_probe_schema_untouched(): void
    {
        DB::statement('CREATE DATABASE IF NOT EXISTS `'.self::PROBE_SCHEMA.'` CHARACTER SET utf8mb4');
        $this->schemaCreated = true;

        $before = $this->tableCount(self::PROBE_SCHEMA);
        $this->assertSame(0, $before, 'Sonda musí začínat prázdná.');

        $marker = $this->workspace().'/migrated';

        // Jediný nepřátelský vstup je DB_URL; zbytek se schválně nastaví
        // na legitimní hodnoty hlavní sady, aby se neodmítlo něco jiného.
        $command = sprintf(
            'cd %s && DB_CONNECTION=sqlite DB_DATABASE=%s DB_URL=%s %s=%s '
            .'./vendor/bin/phpunit -c phpunit.xml tests/Support/DestructiveMigrationProbeTest.php 2>&1',
            escapeshellarg(base_path()),
            escapeshellarg(':memory:'),
            escapeshellarg('mysql://root@127.0.0.1:3306/'.self::PROBE_SCHEMA),
            DestructiveMigrationProbeTest::MARKER_VARIABLE,
            escapeshellarg($marker),
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        $output = implode("\n", $output);

        $this->assertNotSame(0, $exitCode, "Hlavní sada musela selhat. Výstup:\n{$output}");
        $this->assertStringContainsString(self::PROBE_SCHEMA, $output);
        $this->assertFileDoesNotExist(
            $marker,
            'Závora musí odmítnout PŘED migrate:fresh — destruktivní krok se nesmí spustit.',
        );
        $this->assertSame(
            0,
            $this->tableCount(self::PROBE_SCHEMA),
            'V podstrčeném schématu nesmí vzniknout ani zmizet žádná tabulka.',
        );
    }
}
