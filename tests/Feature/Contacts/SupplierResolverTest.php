<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Domain\Ares\AresClient;
use App\Domain\Ares\AresSubject;
use App\Domain\Ares\AresUnavailable;
use App\Domain\Contacts\SupplierNotResolvable;
use App\Domain\Contacts\SupplierResolver;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ContactType;
use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierResolverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($this->organization);
    }

    private function resolver(?AresClient $ares = null): SupplierResolver
    {
        return new SupplierResolver($ares ?? $this->aresReturning($this->skodaSubject()));
    }

    private function skodaSubject(): AresSubject
    {
        return AresSubject::fromRegistryData([
            'ico' => '00177041',
            'obchodniJmeno' => 'Škoda Auto a.s.',
            'dic' => 'CZ00177041',
            'sidlo' => [
                'kodStatu' => 'CZ', 'nazevObce' => 'Mladá Boleslav',
                'nazevUlice' => 'tř. Václava Klementa', 'cisloDomovni' => 869, 'psc' => 29301,
            ],
            'seznamRegistraci' => ['stavZdrojeDph' => 'AKTIVNI'],
        ]);
    }

    private function aresReturning(?AresSubject $subject): AresClient
    {
        return new class($subject) implements AresClient
        {
            public int $calls = 0;

            public function __construct(private readonly ?AresSubject $subject)
            {
            }

            public function findByIco(string $ico): ?AresSubject
            {
                $this->calls++;

                return $this->subject;
            }

            public function searchByName(string $name, int $limit = 10): array
            {
                return [];
            }
        };
    }

    private function aresFailing(): AresClient
    {
        return new class implements AresClient
        {
            public function findByIco(string $ico): ?AresSubject
            {
                throw AresUnavailable::because('test');
            }

            public function searchByName(string $name, int $limit = 10): array
            {
                throw AresUnavailable::because('test');
            }
        };
    }

    public function test_creates_supplier_from_registry_when_unknown(): void
    {
        $contact = $this->resolver()->resolveByIco('00177041');

        $this->assertTrue($contact->exists);
        $this->assertSame('Škoda Auto a.s.', $contact->name);
        $this->assertSame('00177041', $contact->ico);
        $this->assertSame('CZ00177041', $contact->dic);
        $this->assertSame('tř. Václava Klementa 869', $contact->street);
        $this->assertSame('293 01', $contact->zip);
        $this->assertSame(ContactType::Supplier, $contact->type);
        $this->assertSame($this->organization->id, $contact->organization_id);
    }

    public function test_reuses_existing_supplier_and_skips_registry(): void
    {
        $existing = Contact::factory()->create([
            'organization_id' => $this->organization->id,
            'ico' => '00177041',
            'type' => ContactType::Supplier,
        ]);

        $ares = $this->aresReturning($this->skodaSubject());
        $contact = $this->resolver($ares)->resolveByIco('00177041');

        $this->assertSame($existing->id, $contact->id);
        $this->assertSame(0, $ares->calls, 'Na známé IČO se ARESu neptáme.');
        $this->assertSame(1, Contact::query()->where('ico', '00177041')->count());
    }

    public function test_matches_existing_supplier_regardless_of_leading_zeros(): void
    {
        $existing = Contact::factory()->create([
            'organization_id' => $this->organization->id,
            'ico' => '00177041',
        ]);

        $this->assertSame($existing->id, $this->resolver()->resolveByIco('177041')->id);
    }

    public function test_promotes_customer_to_both_instead_of_creating_duplicate(): void
    {
        $customer = Contact::factory()->create([
            'organization_id' => $this->organization->id,
            'ico' => '00177041',
            'type' => ContactType::Customer,
        ]);

        $contact = $this->resolver()->resolveByIco('00177041');

        $this->assertSame($customer->id, $contact->id);
        $this->assertSame(ContactType::Both, $contact->fresh()->type);
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_does_not_reuse_contact_from_another_organization(): void
    {
        $otherOrganization = Organization::factory()->create();
        Contact::withoutGlobalScope('organization')->create([
            'organization_id' => $otherOrganization->id,
            'ico' => '00177041',
            'name' => 'Cizí kopie',
            'type' => ContactType::Supplier,
        ]);

        $contact = $this->resolver()->resolveByIco('00177041');

        $this->assertSame($this->organization->id, $contact->organization_id);
        $this->assertSame(
            2,
            Contact::withoutGlobalScope('organization')->where('ico', '00177041')->count(),
            'Každá organizace má vlastní kontakt pro stejné IČO.',
        );
    }

    public function test_falls_back_to_manual_name_when_registry_is_down(): void
    {
        $contact = $this->resolver($this->aresFailing())
            ->resolveByIco('00177041', 'Ručně zadaný dodavatel s.r.o.');

        $this->assertSame('Ručně zadaný dodavatel s.r.o.', $contact->name);
        $this->assertSame('00177041', $contact->ico);
    }

    public function test_manual_name_wins_over_registry_name(): void
    {
        $contact = $this->resolver()->resolveByIco('00177041', 'Vlastní označení');

        $this->assertSame('Vlastní označení', $contact->name);
        // Ostatní údaje se z registru převezmou.
        $this->assertSame('Mladá Boleslav', $contact->city);
    }

    public function test_fails_when_registry_is_down_and_no_name_given(): void
    {
        $this->expectException(SupplierNotResolvable::class);

        $this->resolver($this->aresFailing())->resolveByIco('00177041');
    }

    public function test_fails_when_subject_not_found_and_no_name_given(): void
    {
        $this->expectException(SupplierNotResolvable::class);

        $this->resolver($this->aresReturning(null))->resolveByIco('00177041');
    }

    public function test_creates_contact_from_name_when_subject_not_found(): void
    {
        $contact = $this->resolver($this->aresReturning(null))
            ->resolveByIco('00177041', 'Novák nábytek');

        $this->assertSame('Novák nábytek', $contact->name);
    }

    public function test_rejects_ico_that_cannot_be_normalized(): void
    {
        $this->expectException(SupplierNotResolvable::class);

        $this->resolver()->resolveByIco('nesmysl');
    }
}
