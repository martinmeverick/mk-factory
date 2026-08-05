<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Základ pro testy skutečného souběhu nad MariaDB.
 *
 * Proč NE hlavní SQLite sada: SQLite nemá `SELECT … FOR UPDATE` (je to
 * no-op) a Laravel testy běží v jediné transakci na jednom spojení —
 * zámky by se tak nikdy neprojevily a test by souběh jen předstíral.
 *
 * Tyto testy proto:
 *  - běží proti skutečné MariaDB (spojení `mysql`, databáze z DB_DATABASE),
 *  - NEPOUŽÍVAJÍ RefreshDatabase (data musí být commitnutá, aby je viděl
 *    druhý proces),
 *  - forkují skutečné procesy, každý s vlastním DB spojením.
 *
 * Spuštění: `composer test:concurrency` (viz README).
 */
abstract class ConcurrencyTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('Souběžné testy vyžadují rozšíření pcntl.');
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Souběžné testy vyžadují MariaDB/MySQL. Spusťte je přes `composer test:concurrency`.'
            );
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::disconnect();
        }

        parent::tearDown();
    }

    /**
     * Spustí zadané uzávěry ve skutečně samostatných procesech, každý
     * s vlastním DB spojením. Vrací chybové hlášky potomků (prázdný
     * řetězec = potomek uspěl).
     *
     * @param  list<\Closure>  $workers
     * @return list<string>
     */
    protected function runInParallel(array $workers): array
    {
        $sockets = [];
        $children = [];

        foreach ($workers as $index => $worker) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            if ($pair === false) {
                $this->fail('Nepodařilo se vytvořit socket pro potomka.');
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork() selhal.');
            }

            if ($pid === 0) {
                // Potomek: vlastní DB spojení, ne zděděné po rodiči.
                fclose($pair[0]);
                DB::disconnect();

                $error = '';

                try {
                    $worker();
                } catch (\Throwable $e) {
                    $error = get_class($e).': '.$e->getMessage();
                }

                fwrite($pair[1], $error);
                fclose($pair[1]);

                // Bez shutdown handlerů PHPUnitu — potomek nesmí reportovat testy.
                exit(0);
            }

            fclose($pair[1]);
            $sockets[$index] = $pair[0];
            $children[$index] = $pid;
        }

        $errors = [];

        foreach ($children as $index => $pid) {
            pcntl_waitpid($pid, $status);
            $errors[$index] = (string) stream_get_contents($sockets[$index]);
            fclose($sockets[$index]);
        }

        ksort($errors);

        return array_values($errors);
    }
}
