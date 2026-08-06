<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Domain\Contacts\AmbiguousCustomerMatch;
use App\Domain\Contacts\CustomerResolver;
use App\Domain\Tenancy\CurrentOrganization;
use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * RE-REVIEW: dva souběžné email-only požadavky (bez external_id i IČO)
 * dřív prošly lookupem naprázdno a založily dva kontakty — e-mail unikátní
 * index nemá a mít nesmí. Souběh teď serializuje tenant-scoped GET_LOCK
 * nad kanonickým e-mailem a druhý požadavek po získání zámku najde kontakt
 * založený prvním.
 */
class CustomerResolverConcurrencyTest extends ConcurrencyTestCase
{
    private Organization $organization;

    private function seedOrganization(): void
    {
        $this->organization = Organization::withoutGlobalScope('organization')->create([
            'name' => 'Souběh resolveru s.r.o.',
            'country' => 'CZ',
        ]);
    }

    private function resolverFor(?int $lockTimeoutSeconds = null): CustomerResolver
    {
        app(CurrentOrganization::class)->set(
            Organization::withoutGlobalScope('organization')->findOrFail($this->organization->id)
        );

        return new CustomerResolver(
            $lockTimeoutSeconds ?? CustomerResolver::DEFAULT_LOCK_TIMEOUT_SECONDS
        );
    }

    public function test_concurrent_email_only_requests_create_a_single_contact(): void
    {
        $this->seedOrganization();
        $organizationId = $this->organization->id;

        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier): void {
                $resolver = $this->resolverFor();
                $barrier();
                $resolver->resolve(['name' => 'Jana Nováková', 'email' => 'jana@example.com']);
            },
            function (WorkerBarrier $barrier): void {
                $resolver = $this->resolverFor();
                $barrier();
                // Jiná velikost písmen — kanonizace musí obě adresy spojit.
                $resolver->resolve(['name' => 'Jana Nováková', 'email' => 'JANA@Example.com']);
            },
        ]);

        // Žádná neošetřená unique/deadlock výjimka nesmí uniknout.
        $this->assertSame(['', ''], $errors, 'Oba požadavky musí uspět: '.implode(' | ', $errors));

        $contacts = Contact::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereRaw('LOWER(email) = ?', ['jana@example.com'])
            ->get();

        $this->assertCount(1, $contacts, 'Souběh nesmí vytvořit duplicitní identitu.');
        $this->assertSame('jana@example.com', $contacts->first()->email, 'Uložená hodnota je kanonická.');
    }

    /**
     * RE-REVIEW, nález 4: GET_LOCK je vázaný na SESSION, ne na transakci.
     * Uvnitř nadřazené transakce by se uvolnil PŘED commitem, druhý worker
     * by nově založený kontakt ještě neviděl a založil by druhý (re-review
     * to reprodukovalo). Kontrakt proto zní „resolve PŘED transakcí“
     * a jeho porušení se hlásí doménovou výjimkou.
     */
    public function test_resolver_inside_an_outer_transaction_is_refused(): void
    {
        $this->seedOrganization();
        $organizationId = $this->organization->id;

        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier): void {
                $resolver = $this->resolverFor();
                $barrier();

                DB::transaction(function () use ($resolver): void {
                    $resolver->resolve(['name' => 'Jana Nováková', 'email' => 'jana@example.com']);
                    // Commit by přišel až tady — zámek by mezitím padl.
                    usleep(200_000);
                });
            },
            function (WorkerBarrier $barrier): void {
                $resolver = $this->resolverFor();
                $barrier();

                DB::transaction(function () use ($resolver): void {
                    $resolver->resolve(['name' => 'Jana Nováková', 'email' => 'jana@example.com']);
                });
            },
        ]);

        foreach ($errors as $index => $error) {
            $this->assertStringContainsString(
                'CustomerResolutionNotTransactional',
                $error,
                "Worker {$index} měl být odmítnut, dostal: ".var_export($error, true),
            );
        }

        $this->assertSame(
            0,
            Contact::withoutGlobalScope('organization')->where('organization_id', $organizationId)->count(),
            'Odmítnutý resolver nesmí založit žádný kontakt — natož duplicitní.',
        );
    }

    /**
     * Silnější klíč zámek nepotřebuje (kryje ho unikátní index), takže
     * uvnitř transakce běžet SMÍ — kontrakt omezuje jen email-only větev.
     */
    public function test_resolver_with_external_id_may_run_inside_a_transaction(): void
    {
        $this->seedOrganization();
        $organizationId = $this->organization->id;

        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier): void {
                $resolver = $this->resolverFor();
                $barrier();

                DB::transaction(function () use ($resolver): void {
                    $resolver->resolve([
                        'name' => 'Jana Nováková',
                        'external_id' => 'ujabka-cust-1',
                        'email' => 'jana@example.com',
                    ]);
                });
            },
        ]);

        $this->assertSame([''], $errors, 'Silnější klíč nesmí být odmítnut: '.implode(' | ', $errors));
        $this->assertSame(
            1,
            Contact::withoutGlobalScope('organization')->where('organization_id', $organizationId)->count(),
        );
    }

    /**
     * Nedostupný zámek končí DOMÉNOVOU chybou, ne obecnou RuntimeException —
     * budoucí API ji má odlišit od interní chyby a požadavek zopakovat.
     */
    public function test_lock_timeout_raises_a_domain_error(): void
    {
        $this->seedOrganization();
        $organizationId = $this->organization->id;

        // Zámek drží samostatné spojení po celou dobu běhu workeru.
        $holder = $this->holdingConnection();
        $lockName = $this->lockNameFor($organizationId, 'jana@example.com');

        $this->assertSame(
            1,
            (int) $holder->selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$lockName])->acquired,
            'Test si musí zámek nejdřív vzít.',
        );

        try {
            $errors = $this->runInParallel([
                function (WorkerBarrier $barrier): void {
                    // Timeout 1 s, ať test netrvá věčnost.
                    $resolver = $this->resolverFor(1);
                    $barrier();
                    $resolver->resolve(['name' => 'Jana Nováková', 'email' => 'jana@example.com']);
                },
            ]);
        } finally {
            $holder->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
            $holder->disconnect();
        }

        $this->assertStringContainsString('CustomerLockUnavailable', $errors[0]);
        $this->assertSame(
            0,
            Contact::withoutGlobalScope('organization')->where('organization_id', $organizationId)->count(),
            'Při nedostupném zámku nesmí vzniknout kontakt.',
        );
    }

    public function test_lock_is_released_when_the_resolver_throws(): void
    {
        $this->seedOrganization();

        // Dva kontakty se stejným e-mailem ⇒ resolve skončí ambiguitou.
        foreach (['matka', 'otec'] as $who) {
            Contact::withoutGlobalScope('organization')->create([
                'organization_id' => $this->organization->id,
                'type' => 'customer',
                'name' => 'Rodina — '.$who,
                'email' => 'rodina@example.com',
                'country' => 'CZ',
            ]);
        }

        $resolver = $this->resolverFor();

        try {
            $resolver->resolve(['name' => 'Kdokoli', 'email' => 'rodina@example.com']);
            $this->fail('Dvojznačný e-mail musí skončit AmbiguousCustomerMatch.');
        } catch (AmbiguousCustomerMatch) {
            // očekáváno
        }

        // Zámek musí být volný — kontroluje ho JINÉ spojení, protože
        // držící session by u vlastního zámku dostala matoucí odpověď.
        $observer = $this->holdingConnection();
        $lockName = $this->lockNameFor($this->organization->id, 'rodina@example.com');

        try {
            $this->assertSame(
                1,
                (int) $observer->selectOne('SELECT IS_FREE_LOCK(?) AS free', [$lockName])->free,
                'Zámek musí být uvolněn i když resolver vyhodil výjimku.',
            );
        } finally {
            $observer->disconnect();
        }
    }

    /**
     * Samostatné spojení pro držení/pozorování zámku — GET_LOCK je vázaný
     * na session, takže z téhož spojení by se nic neprokázalo.
     */
    private function holdingConnection(): Connection
    {
        config(['database.connections.lock_observer' => config('database.connections.mysql')]);
        DB::purge('lock_observer');

        return DB::connection('lock_observer');
    }

    /**
     * Musí odpovídat CustomerResolver::lockName().
     */
    private function lockNameFor(int $organizationId, string $canonicalEmail): string
    {
        return 'mkf:cust:'.substr(
            hash('sha256', DB::connection()->getDatabaseName().'|'.$organizationId.'|'.$canonicalEmail),
            0,
            40,
        );
    }

    public function test_lock_is_tenant_scoped_so_other_organizations_are_not_serialised_together(): void
    {
        $this->seedOrganization();

        $otherOrganization = Organization::withoutGlobalScope('organization')->create([
            'name' => 'Druhá organizace s.r.o.',
            'country' => 'CZ',
        ]);

        $firstId = $this->organization->id;
        $otherId = $otherOrganization->id;

        $errors = $this->runInParallel([
            function (WorkerBarrier $barrier): void {
                $resolver = $this->resolverFor();
                $barrier();
                $resolver->resolve(['name' => 'Jana Nováková', 'email' => 'jana@example.com']);
            },
            function (WorkerBarrier $barrier) use ($otherId): void {
                app(CurrentOrganization::class)->set(
                    Organization::withoutGlobalScope('organization')->findOrFail($otherId)
                );
                $resolver = new CustomerResolver;
                $barrier();
                $resolver->resolve(['name' => 'Jiná Jana', 'email' => 'jana@example.com']);
            },
        ]);

        $this->assertSame(['', ''], $errors, 'Oba požadavky musí uspět: '.implode(' | ', $errors));

        // Stejný e-mail v jiné organizaci se nebere v úvahu — vzniknou dva
        // kontakty, každý pod svou organizací.
        foreach ([$firstId, $otherId] as $organizationId) {
            $this->assertSame(
                1,
                Contact::withoutGlobalScope('organization')
                    ->where('organization_id', $organizationId)
                    ->whereRaw('LOWER(email) = ?', ['jana@example.com'])
                    ->count(),
                "Organizace {$organizationId} musí mít právě jeden kontakt.",
            );
        }
    }
}
