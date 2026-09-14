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
 *  - synchronizují se JEDNORÁZOVOU bariérou: každý worker dokončí přípravu,
 *    ohlásí rodiči READY a blokuje; rodič potvrdí, že bariéry dosáhli
 *    VŠICHNI, a teprve pak je současně uvolní GO,
 *  - před `migrate:fresh` prověří databázi (ConcurrencyDatabaseGuard) —
 *    destruktivní operace nesmí dopadnout na jiné než testovací schéma.
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

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped(
                'Souběžné testy vyžadují MariaDB/MySQL. Spusťte je přes `composer test:concurrency`.'
            );
        }

        // Fail closed PŘED jakoukoli destruktivní operací. Bez této kontroly
        // by stačilo přepsat DB_DATABASE proměnnou prostředí a migrate:fresh
        // by zahodil cizí schéma.
        ConcurrencyDatabaseGuard::assertSafe(DB::connection());

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::disconnect();
        }

        parent::tearDown();
    }

    /**
     * Spustí zadané uzávěry ve skutečně samostatných procesech, každý
     * s vlastním DB spojením, synchronizované jednorázovou bariérou.
     *
     * Každý worker dostane `WorkerBarrier $barrier` (volá se jako
     * `$barrier();`) a MUSÍ ho zavolat právě jednou: před bariérou patří
     * příprava, za bariéru samotná konkurenční operace. Vrací chybové
     * hlášky potomků (prázdný řetězec = potomek uspěl).
     *
     * Porušení protokolu (chybějící bariéra, bariéra navíc, poškozená nebo
     * neznámá zpráva) NIKDY nevrací jako výsledek workeru — vyhodí
     * ProtocolViolation, takže test spadne místo falešného úspěchu.
     *
     * @param  list<\Closure(WorkerBarrier): void>  $workers
     * @return list<string>
     *
     * @throws ProtocolViolation
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
            // Čte se od VŠECH, i když první ohlásí porušení protokolu —
            // jinak by zbylí workeři viseli na fgets() a report by byl
            // neúplný.
            $violations = [];

            foreach ($sockets as $index => $socket) {
                stream_set_timeout($socket, self::BARRIER_TIMEOUT_SECONDS);

                try {
                    $message = BarrierProtocol::decode(fgets($socket), "čekání na bariéru workeru {$index}");
                } catch (ProtocolViolation $e) {
                    $violations[] = $e->getMessage();

                    continue;
                }

                if ($message['type'] === BarrierProtocol::ERROR) {
                    $violations[] = ProtocolViolation::forWorker($index, $message['payload'])->getMessage();

                    continue;
                }

                if ($message['type'] !== BarrierProtocol::READY) {
                    $violations[] = ProtocolViolation::forUnexpectedType(
                        BarrierProtocol::READY,
                        $message['type'],
                        "čekání na bariéru workeru {$index}",
                    )->getMessage();
                }
            }

            if ($violations !== []) {
                throw new ProtocolViolation(implode(' | ', $violations));
            }

            // Fáze 2: současné uvolnění všech potomků do kritické sekce.
            foreach ($sockets as $socket) {
                fwrite($socket, BarrierProtocol::go());
            }

            // Fáze 3: výsledky. Typovaná zpráva + striktní base64 — poškozený
            // payload se nesmí tiše proměnit v prázdnou (úspěšnou) hlášku.
            foreach ($sockets as $index => $socket) {
                stream_set_timeout($socket, self::RESULT_TIMEOUT_SECONDS);

                $message = BarrierProtocol::decode(fgets($socket), "výsledek workeru {$index}");

                if ($message['type'] === BarrierProtocol::ERROR) {
                    throw ProtocolViolation::forWorker($index, $message['payload']);
                }

                if ($message['type'] !== BarrierProtocol::RESULT) {
                    throw ProtocolViolation::forUnexpectedType(
                        BarrierProtocol::RESULT,
                        $message['type'],
                        "výsledek workeru {$index}",
                    );
                }

                $errors[$index] = $message['payload'];
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
     * @param  \Closure(WorkerBarrier): void  $worker
     * @param  resource  $socket
     */
    private function runWorker(\Closure $worker, $socket): never
    {
        try {
            // Pojistka k odpojení rodiče před forkem — potomek začíná bez
            // PDO a první dotaz mu otevře vlastní spojení.
            DB::disconnect();

            $barrier = new WorkerBarrier($socket, self::BARRIER_TIMEOUT_SECONDS);

            $error = '';
            $protocolError = null;

            try {
                $worker($barrier);
            } catch (ProtocolViolation $e) {
                $protocolError = $e->getMessage();
            } catch (\Throwable $e) {
                $error = get_class($e).': '.$e->getMessage();
            }

            if ($protocolError === null && $barrier->callCount() === 0) {
                $protocolError = ProtocolViolation::forMissingBarrier()->getMessage()
                    .($error === '' ? '' : ' Worker navíc skončil chybou: '.$error);
            }

            // ERROR se posílá i místo READY: worker, který bariéry nedosáhl,
            // by jinak nechal rodiče viset. Rodič ho pozná podle typu zprávy
            // v obou fázích a shodí test.
            WorkerBarrier::writeQuietly($socket, $protocolError !== null
                ? BarrierProtocol::error($protocolError)
                : BarrierProtocol::result($error));
        } catch (\Throwable) {
            // Potomek nesmí za žádných okolností reportovat testy ani nechat
            // uniknout výjimku — rodič už mohl kanál zavřít (rozbitá roura).
        } finally {
            if (is_resource($socket)) {
                @fclose($socket);
            }

            // Bez shutdown handlerů PHPUnitu.
            exit(0);
        }
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
