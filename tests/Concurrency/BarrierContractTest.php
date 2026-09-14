<?php

declare(strict_types=1);

namespace Tests\Concurrency;

/**
 * RE-REVIEW, nález 8: bariéra musí být JEDNORÁZOVÁ a protokol striktní.
 *
 * Testy samotné testovací infrastruktury — bez nich stojí důkazní síla
 * všech souběžných testů na tom, že se workery chovají slušně. Ověřuje se
 * chování při chybějící bariéře, bariéře navíc, poškozeném payloadu
 * i neznámé zprávě, a to, že po protokolové chybě nezůstanou viset
 * potomci ani sockety.
 */
class BarrierContractTest extends ConcurrencyTestCase
{
    public function test_worker_with_exactly_one_barrier_call_passes(): void
    {
        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier): void {
                $barrier();
            },
            function (WorkerBarrier $barrier): void {
                $barrier();
            },
        ]);

        $this->assertSame(['', ''], $errors);
        $this->assertNoChildrenLeft();
    }

    public function test_worker_without_a_barrier_call_fails_the_test(): void
    {
        try {
            $this->runInParallel([
                function (WorkerBarrier $barrier): void {
                    $barrier();
                },
                function (WorkerBarrier $barrier): void {
                    // Bariéra se schválně nevolá.
                },
            ]);

            $this->fail('Worker bez bariéry musí shodit test.');
        } catch (ProtocolViolation $e) {
            $this->assertStringContainsString('nezavolal $barrier()', $e->getMessage());
        }

        $this->assertNoChildrenLeft();
    }

    /**
     * Jádro nálezu: druhé volání bariéry se dřív odeslalo jako READY,
     * rodič ho přečetl místo výsledku a test falešně prošel.
     */
    public function test_worker_with_two_barrier_calls_fails_the_test(): void
    {
        try {
            $this->runInParallel([
                function (WorkerBarrier $barrier): void {
                    $barrier();
                },
                function (WorkerBarrier $barrier): void {
                    $barrier();
                    $barrier();
                },
            ]);

            $this->fail('Dvojí volání bariéry musí shodit test.');
        } catch (ProtocolViolation $e) {
            $this->assertStringContainsString('jednorázová', $e->getMessage());
        }

        $this->assertNoChildrenLeft();
    }

    public function test_second_barrier_call_never_reaches_the_channel(): void
    {
        // Kdyby druhé volání poslalo READY, rodič by ho přečetl jako
        // výsledek. Výjimka proto letí PŘED zápisem do kanálu.
        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier): void {
                $barrier();

                try {
                    $barrier();
                } catch (ProtocolViolation) {
                    // Worker si výjimku odchytil, ale počítadlo zůstává.
                }

                if ($barrier->callCount() !== 2) {
                    throw new \RuntimeException('Počítadlo volání bariéry nesedí.');
                }
            },
        ]);

        $this->assertSame([''], $errors, 'Kanál nesmí obsahovat druhé READY.');
        $this->assertNoChildrenLeft();
    }

    public function test_corrupted_base64_result_is_rejected(): void
    {
        try {
            $this->runInParallel([
                function (WorkerBarrier $barrier): void {
                    $barrier();
                    $barrier->writeRawForProtocolTest("RESULT:!!!not-base64!!!\n");
                },
            ]);

            $this->fail('Poškozený base64 payload musí shodit test.');
        } catch (ProtocolViolation $e) {
            $this->assertStringContainsString('base64', $e->getMessage());
        }

        $this->assertNoChildrenLeft();
    }

    public function test_unknown_message_type_is_rejected(): void
    {
        try {
            $this->runInParallel([
                function (WorkerBarrier $barrier): void {
                    $barrier();
                    $barrier->writeRawForProtocolTest("HOTOVO\n");
                },
            ]);

            $this->fail('Neznámý typ zprávy musí shodit test.');
        } catch (ProtocolViolation $e) {
            $this->assertStringContainsString('Neznámý typ zprávy', $e->getMessage());
        }

        $this->assertNoChildrenLeft();
    }

    public function test_worker_exception_is_transferred_verbatim(): void
    {
        $message = "Doménová chyba s diakritikou a\nnovým řádkem — 1 234,56 Kč";

        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier) use ($message): void {
                $barrier();

                throw new \DomainException($message);
            },
            function (WorkerBarrier $barrier): void {
                $barrier();
            },
        ]);

        $this->assertSame('DomainException: '.$message, $errors[0]);
        $this->assertSame('', $errors[1]);
        $this->assertNoChildrenLeft();
    }

    /**
     * Cleanup musí proběhnout i při protokolové chybě: `finally` zavře
     * sockety a sklidí (případně SIGKILLne) potomky. Zbylý zombie proces
     * by držel InnoDB transakci a rozbil následující test.
     */
    private function assertNoChildrenLeft(): void
    {
        $status = 0;

        $this->assertSame(
            -1,
            pcntl_waitpid(-1, $status, WNOHANG),
            'Po běhu nesmí zůstat žádný neuklizený potomek.',
        );
    }
}
