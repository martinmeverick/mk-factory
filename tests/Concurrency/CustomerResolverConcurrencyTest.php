<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Domain\Contacts\CustomerResolver;
use App\Domain\Tenancy\CurrentOrganization;
use App\Models\Contact;
use App\Models\Organization;

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

    private function resolverFor(): CustomerResolver
    {
        app(CurrentOrganization::class)->set(
            Organization::withoutGlobalScope('organization')->findOrFail($this->organization->id)
        );

        return new CustomerResolver;
    }

    public function test_concurrent_email_only_requests_create_a_single_contact(): void
    {
        $this->seedOrganization();
        $organizationId = $this->organization->id;

        $errors = $this->runInParallel([
            function (callable $barrier): void {
                $resolver = $this->resolverFor();
                $barrier();
                $resolver->resolve(['name' => 'Jana Nováková', 'email' => 'jana@example.com']);
            },
            function (callable $barrier): void {
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
            function (callable $barrier): void {
                $resolver = $this->resolverFor();
                $barrier();
                $resolver->resolve(['name' => 'Jana Nováková', 'email' => 'jana@example.com']);
            },
            function (callable $barrier) use ($otherId): void {
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
