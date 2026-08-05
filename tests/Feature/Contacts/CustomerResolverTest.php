<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Domain\Contacts\CustomerResolver;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ContactType;
use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Automatické fakturování z napojeného e-shopu (U Jabka): odběratelem je
 * převážně fyzická osoba BEZ IČO, párování proto stojí na external_id.
 */
class CustomerResolverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private CustomerResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($this->organization);
        $this->resolver = new CustomerResolver;
    }

    public function test_creates_individual_without_ico(): void
    {
        $contact = $this->resolver->resolve([
            'name' => 'Jana Nováková',
            'external_id' => 'ujabka-cust-1042',
            'email' => 'jana@example.test',
            'street' => 'Krátká 5',
            'city' => 'Brno',
            'zip' => '602 00',
        ]);

        $this->assertTrue($contact->exists);
        $this->assertNull($contact->ico);
        $this->assertNull($contact->dic);
        $this->assertSame('ujabka-cust-1042', $contact->external_id);
        $this->assertSame(ContactType::Customer, $contact->type);
        $this->assertSame($this->organization->id, $contact->organization_id);
    }

    public function test_many_individuals_without_ico_can_coexist(): void
    {
        foreach (['a', 'b', 'c'] as $index => $key) {
            $this->resolver->resolve([
                'name' => 'Zákazník '.$key,
                'external_id' => 'ujabka-'.$key,
                'email' => $key.'@example.test',
            ]);
        }

        $this->assertSame(3, Contact::query()->count());
        $this->assertSame(3, Contact::query()->whereNull('ico')->count());
    }

    public function test_repeated_order_reuses_customer_by_external_id(): void
    {
        $first = $this->resolver->resolve([
            'name' => 'Jana Nováková',
            'external_id' => 'ujabka-cust-1042',
            'email' => 'jana@example.test',
        ]);

        // Druhá objednávka, mezitím si změnila e-mail i příjmení.
        $second = $this->resolver->resolve([
            'name' => 'Jana Svobodová',
            'external_id' => 'ujabka-cust-1042',
            'email' => 'jana.svobodova@example.test',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_matches_company_order_by_ico(): void
    {
        $existing = Contact::factory()->create([
            'organization_id' => $this->organization->id,
            'ico' => '00177041',
            'type' => ContactType::Customer,
        ]);

        $contact = $this->resolver->resolve([
            'name' => 'Škoda Auto a.s.',
            'ico' => '177041',
        ]);

        $this->assertSame($existing->id, $contact->id);
    }

    public function test_falls_back_to_email_when_external_id_missing(): void
    {
        $existing = $this->resolver->resolve([
            'name' => 'Petr Dvořák',
            'email' => 'petr@example.test',
        ]);

        $again = $this->resolver->resolve([
            'name' => 'Petr Dvořák',
            'email' => 'petr@example.test',
        ]);

        $this->assertSame($existing->id, $again->id);
    }

    public function test_backfills_external_id_on_known_customer(): void
    {
        $existing = Contact::factory()->create([
            'organization_id' => $this->organization->id,
            'ico' => null,
            'email' => 'petr@example.test',
            'external_id' => null,
            'type' => ContactType::Customer,
        ]);

        $contact = $this->resolver->resolve([
            'name' => 'Petr Dvořák',
            'email' => 'petr@example.test',
            'external_id' => 'ujabka-cust-7',
        ]);

        $this->assertSame($existing->id, $contact->id);
        $this->assertSame('ujabka-cust-7', $contact->fresh()->external_id);
    }

    public function test_promotes_supplier_to_both_when_ordering(): void
    {
        $supplier = Contact::factory()->create([
            'organization_id' => $this->organization->id,
            'ico' => '00177041',
            'type' => ContactType::Supplier,
        ]);

        $contact = $this->resolver->resolve(['name' => 'Škoda Auto a.s.', 'ico' => '00177041']);

        $this->assertSame($supplier->id, $contact->id);
        $this->assertSame(ContactType::Both, $contact->fresh()->type);
    }

    public function test_does_not_match_customer_from_another_organization(): void
    {
        $otherOrganization = Organization::factory()->create();
        Contact::withoutGlobalScope('organization')->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Cizí zákazník',
            'external_id' => 'ujabka-cust-1042',
            'type' => ContactType::Customer,
        ]);

        $contact = $this->resolver->resolve([
            'name' => 'Jana Nováková',
            'external_id' => 'ujabka-cust-1042',
        ]);

        $this->assertSame($this->organization->id, $contact->organization_id);
        $this->assertSame(
            2,
            Contact::withoutGlobalScope('organization')->where('external_id', 'ujabka-cust-1042')->count(),
        );
    }

    public function test_blank_values_are_stored_as_null(): void
    {
        $contact = $this->resolver->resolve([
            'name' => '  Jana Nováková  ',
            'external_id' => '',
            'ico' => '',
            'email' => '   ',
        ]);

        $this->assertSame('Jana Nováková', $contact->name);
        $this->assertNull($contact->external_id);
        $this->assertNull($contact->ico);
        $this->assertNull($contact->email);
    }
}
