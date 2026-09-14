<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Illuminate\Support\Facades\DB;

/**
 * Vlastnosti samotné testovací infrastruktury, na kterých stojí důkazní
 * síla souběžných testů: každý worker má VLASTNÍ DB spojení (žádné zděděné
 * PDO po forku) a bariéra je POVINNÁ — worker, který ji nezavolá, test
 * srozumitelně shodí místo tichého nedeterminismu.
 */
class ForkIsolationTest extends ConcurrencyTestCase
{
    public function test_each_worker_gets_its_own_database_connection(): void
    {
        $record = function (string $key): void {
            DB::table('cache')->insert([
                'key' => $key,
                'value' => (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
                'expiration' => time() + 300,
            ]);
        };

        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier) use ($record): void {
                // První dotaz potomka otevírá spojení — schválně PŘED
                // bariérou, ať se izolace prokáže i pro přípravnou fázi.
                $record('conn-a');
                $barrier();
                $record('conn-a-after');
            },
            function (WorkerBarrier $barrier) use ($record): void {
                $record('conn-b');
                $barrier();
                $record('conn-b-after');
            },
        ]);

        $this->assertSame(['', ''], $errors, 'Oba workery musí projít: '.implode(' | ', $errors));

        $ids = DB::table('cache')
            ->whereIn('key', ['conn-a', 'conn-b'])
            ->pluck('value', 'key');

        $parentId = (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;

        $this->assertCount(2, $ids, 'Oba workery musely zapsat své CONNECTION_ID.');
        $this->assertNotSame($ids['conn-a'], $ids['conn-b'], 'Workery nesmí sdílet jedno spojení.');
        $this->assertNotSame($parentId, $ids['conn-a'], 'Worker nesmí sdílet spojení s rodičem.');
        $this->assertNotSame($parentId, $ids['conn-b'], 'Worker nesmí sdílet spojení s rodičem.');

        // Spojení potomka zůstává stabilní přes bariéru (žádný reconnect).
        $after = DB::table('cache')->whereIn('key', ['conn-a-after', 'conn-b-after'])->pluck('value', 'key');
        $this->assertSame($ids['conn-a'], $after['conn-a-after']);
        $this->assertSame($ids['conn-b'], $after['conn-b-after']);
    }
}
