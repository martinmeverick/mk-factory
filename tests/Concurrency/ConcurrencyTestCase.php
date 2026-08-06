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
 *  - forkují skutečné procesy, každý s VLASTNÍM DB spojením (rodič se
 *    odpojí PŘED forkem, potomci tedy žádné PDO nezdědí),
 *  - synchronizují se DETERMINISTICKOU bariérou: každý worker dokončí
 *    přípravu, ohlásí rodiči READY a blokuje; rodič potvrdí, že bariéry
 *    dosáhli VŠICHNI, a teprve pak je současně uvolní GO. Bez bariéry by
 *    překryv kritických sekcí závisel jen na náhodě plánovače.
 *
 * Spuštění: `composer test:concurrency` (viz README).
 */
abstract class ConcurrencyTestCase extends BaseTestCase
{
    /**
     * Maximum čekání rodiče na READY potomka a potomka na GO rodiče.
     */
    private const int BARRIER_TIMEOUT_SECONDS = 30;

    /**
     * Maximum čekání na výsledek potomka — kryje i čekání na InnoDB zámku
     * (innodb_lock_wait_timeout bývá 50 s).
     */
    private const int RESULT_TIMEOUT_SECONDS = 120;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
            $this->markTestSkipped('Souběžné testy vyžadují rozšíření pcntl a posix.');
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
     * s vlastním DB spojením, synchronizované bariérou.
     *
     * Každý worker dostane `callable $barrier` a MUSÍ ho zavolat právě
     * jednou: před bariérou patří příprava (vlastní spojení, načtení
     * modelů), za bariéru samotná konkurenční operace. Vrací chybové
     * hlášky potomků (prázdný řetězec = potomek uspěl).
     *
     * Synchronizačním prostředkem jsou socketpairy; uklízejí se ve
     * `finally` včetně SIGKILL potomků, kteří po selhání testu visí.
     *
     * @param  list<\Closure(callable(): void): void>  $workers
     * @return list<string>
     */
    protected function runInParallel(array $workers): array
    {
        // Rodič se odpojí PŘED forkem: potomci nezdědí žádné otevřené PDO.
        // Zděděný socket by sdílel MySQL session a COM_QUIT při zavření
        // v potomkovi by zabil spojení i rodiči. Rodič si po forku otevře
        // nové spojení líně.
        DB::disconnect();

        $sockets = [];
        $children = [];
        $errors = [];

        try {
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
                    fclose($pair[0]);
                    $this->runWorker($worker, $pair[1]);
                }

                fclose($pair[1]);
                $sockets[$index] = $pair[0];
                $children[$index] = $pid;
            }

            // Fáze 1: KAŽDÝ potomek musí ohlásit dosažení bariéry. Teprve
            // potvrzení všech zaručuje, že příprava všech workerů skončila.
            foreach ($sockets as $index => $socket) {
                stream_set_timeout($socket, self::BARRIER_TIMEOUT_SECONDS);
                $line = fgets($socket);

                if (trim((string) $line) !== 'READY') {
                    $this->fail(sprintf(
                        'Potomek %d nedosáhl bariéry (přišlo %s) — příprava selhala nebo visí.',
                        $index,
                        var_export($line, true),
                    ));
                }
            }

            // Fáze 2: současné uvolnění všech potomků do kritické sekce.
            foreach ($sockets as $socket) {
                fwrite($socket, "GO\n");
            }

            // Fáze 3: výsledky (base64 — přežijí i víceřádkové výjimky).
            foreach ($sockets as $index => $socket) {
                stream_set_timeout($socket, self::RESULT_TIMEOUT_SECONDS);
                $line = fgets($socket);

                if ($line === false) {
                    $this->fail("Potomek {$index} neodeslal výsledek (timeout nebo pád procesu).");
                }

                $errors[$index] = (string) base64_decode(trim($line), true);
            }
        } finally {
            foreach ($sockets as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }

            $this->reapChildren($children);
        }

        ksort($errors);

        return array_values($errors);
    }

    /**
     * Tělo potomka: vlastní DB spojení, bariéra, operace, odeslání
     * výsledku. Nikdy se nevrací — exit(0) obchází shutdown handlery
     * PHPUnitu, potomek nesmí reportovat testy.
     *
     * @param  \Closure(callable(): void): void  $worker
     * @param  resource  $socket
     */
    private function runWorker(\Closure $worker, $socket): never
    {
        // Pojistka k odpojení rodiče před forkem — potomek začíná bez PDO
        // a první dotaz mu otevře vlastní spojení.
        DB::disconnect();

        $barrierUsed = false;

        $barrier = function () use ($socket, &$barrierUsed): void {
            $barrierUsed = true;

            fwrite($socket, "READY\n");
            stream_set_timeout($socket, self::BARRIER_TIMEOUT_SECONDS);
            $line = fgets($socket);

            if (trim((string) $line) !== 'GO') {
                throw new \RuntimeException('Bariéra nebyla uvolněna: '.var_export($line, true));
            }
        };

        $error = '';

        try {
            $worker($barrier);

            if (! $barrierUsed) {
                $error = 'Worker nezavolal $barrier() — bez bariéry není souběh deterministický.';
            }
        } catch (\Throwable $e) {
            $error = get_class($e).': '.$e->getMessage();
        }

        // Worker, který bariéru nezavolal (typicky pád v přípravě), by
        // rodiče nechal viset na READY — protokol se dorovná dodatečně
        // a chyba odejde ve výsledku.
        if (! $barrierUsed) {
            fwrite($socket, "READY\n");
            stream_set_timeout($socket, self::BARRIER_TIMEOUT_SECONDS);
            fgets($socket);
        }

        fwrite($socket, base64_encode($error)."\n");
        fclose($socket);

        exit(0);
    }

    /**
     * Sklidí všechny potomky; kdo do 5 s neskončí sám (např. visí na
     * bariéře po selhání testu), dostane SIGKILL — InnoDB jeho rozdělanou
     * transakci odvalí.
     *
     * @param  array<int, int>  $children
     */
    private function reapChildren(array $children): void
    {
        foreach ($children as $pid) {
            $deadline = microtime(true) + 5.0;

            do {
                $status = 0;

                if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                    continue 2;
                }

                usleep(20_000);
            } while (microtime(true) < $deadline);

            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }
}
