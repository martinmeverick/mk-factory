<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Domain\Contacts\AmbiguousCustomerMatch;
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

    /**
     * NÁLEZ 5: external_id je nejsilnější klíč. Když pod ním kontakt
     * neexistuje, NESMÍ se párování „zachránit“ e-mailem — jinak by nová
     * identita tiše splynula se starým kontaktem.
     */
    public function test_new_external_id_with_known_email_creates_a_separate_identity(): void
    {
        $existing = Contact::factory()->create([
            'organization_id' => $this->organization->id,
            'ico' => null,
            'email' => 'petr@example.test',
            'external_id' => null,
            'type' => ContactType::Customer,
        ]);

        $contact = $this->resolver->resolve([
            'name' => 'Petr Dvořák mladší',
            'email' => 'petr@example.test',
            'external_id' => 'ujabka-cust-7',
        ]);

        $this->assertNotSame($existing->id, $contact->id, 'Nesmí vrátit starý kontakt.');
        $this->assertSame('ujabka-cust-7', $contact->external_id);
        $this->assertNull($existing->fresh()->external_id, 'Starému kontaktu se cizí klíč nedopisuje.');
        $this->assertSame(2, Contact::query()->count());
    }

    /**
     * NÁLEZ 5: dva kontakty se stejným e-mailem nesmí vést k výběru
     * prvního řádku podle pořadí v databázi.
     */
    public function test_duplicate_email_is_rejected_instead_of_picking_the_first_row(): void
    {
        Contact::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Rodina Nováková — matka',
            'email' => 'rodina@example.test',
            'external_id' => null,
            'ico' => null,
        ]);
        Contact::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Rodina Nováková — otec',
            'email' => 'rodina@example.test',
            'external_id' => null,
            'ico' => null,
        ]);

        $this->expectException(AmbiguousCustomerMatch::class);

        $this->resolver->resolve([
            'name' => 'Kdokoli',
            'email' => 'rodina@example.test',
        ]);
    }

    /**
     * NÁLEZ 5: dodané external_id se nikdy nezachraňuje e-mailem ani IČEM.
     */
    public function test_external_id_lookup_never_falls_back_to_email(): void
    {
        $byEmail = Contact::factory()->create([
            'organization_id' => $this->organization->id,
            'ico' => null,
            'email' => 'firma@example.test',
            'external_id' => null,
        ]);

        $contact = $this->resolver->resolve([
            'name' => 'Nová pobočka',
            'external_id' => 'ujabka-branch-2',
            'email' => 'firma@example.test',
        ]);

        $this->assertNotSame($byEmail->id, $contact->id);
        $this->assertSame('ujabka-branch-2', $contact->external_id);
    }

    /**
     * NÁLEZ 5: konflikt „nové external_id + už obsazené IČO“ se hlásí
     * srozumitelnou doménovou chybou, ne pádem na databázovém indexu
     * a už vůbec ne tichým sloučením dvou identit.
     */
    public function test_conflicting_ico_with_new_external_id_is_reported(): void
    {
        Contact::factory()->create([
            'organization_id' => $this->organization->id,
            'ico' => '00177041',
            'external_id' => 'ujabka-branch-1',
        ]);

        $this->expectException(AmbiguousCustomerMatch::class);

        $this->resolver->resolve([
            'name' => 'Nová pobočka',
            'external_id' => 'ujabka-branch-2',
            'ico' => '00177041',
        ]);
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

    /**
     * RE-REVIEW: e-mail se kanonizuje (trim + lowercase) při ukládání
     * i hledání — 'User@Example.com' a 'user@example.com' jsou táž hodnota.
     */
    public function test_email_is_canonicalised_on_store_and_lookup(): void
    {
        $first = $this->resolver->resolve([
            'name' => 'Jana Nováková',
            'email' => '  User@Example.com  ',
        ]);

        $this->assertSame('user@example.com', $first->email, 'Ukládá se kanonická hodnota.');

        $second = $this->resolver->resolve([
            'name' => 'Jana Nováková',
            'email' => 'user@example.com',
        ]);

        $this->assertSame($first->id, $second->id, 'Kanonicky shodné adresy párují týž kontakt.');
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_lookup_matches_a_manually_created_contact_with_different_case(): void
    {
        $existing = Contact::factory()->create([
            'organization_id' => $this->organization->id,
            'ico' => null,
            'external_id' => null,
            // Ručně založený kontakt s e-mailem v původní velikosti písmen.
            'email' => 'Jana.Novakova@Example.com',
            'type' => ContactType::Customer,
        ]);

        $contact = $this->resolver->resolve([
            'name' => 'Jana Nováková',
            'email' => 'jana.novakova@example.com',
        ]);

        $this->assertSame($existing->id, $contact->id);
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_no_provider_specific_rewrites_are_applied(): void
    {
        $withTag = $this->resolver->resolve([
            'name' => 'S tagem',
            'email' => 'jana+eshop@example.com',
        ]);

        $withDots = $this->resolver->resolve([
            'name' => 'S tečkou navíc',
            'email' => 'j.ana@example.com',
        ]);

        $plain = $this->resolver->resolve([
            'name' => 'Bez tagu',
            'email' => 'jana@example.com',
        ]);

        // +tag ani tečky se NEODSTRAŇUJÍ — jsou to odlišné adresy.
        $this->assertNotSame($withTag->id, $plain->id);
        $this->assertNotSame($withDots->id, $plain->id);
        $this->assertSame(3, Contact::query()->count());
    }

    public function test_case_variant_duplicates_still_raise_ambiguity(): void
    {
        Contact::factory()->create([
            'organization_id' => $this->organization->id,
            'email' => 'rodina@example.test',
            'external_id' => null,
            'ico' => null,
        ]);
        Contact::factory()->create([
            'organization_id' => $this->organization->id,
            'email' => 'Rodina@Example.test',
            'external_id' => null,
            'ico' => null,
        ]);

        $this->expectException(AmbiguousCustomerMatch::class);

        $this->resolver->resolve([
            'name' => 'Kdokoli',
            'email' => 'RODINA@EXAMPLE.TEST',
        ]);
    }

    public function test_same_email_in_another_organization_is_ignored(): void
    {
        $otherOrganization = Organization::factory()->create();
        Contact::withoutGlobalScope('organization')->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Cizí zákazník',
            'email' => 'jana@example.com',
            'type' => ContactType::Customer,
        ]);

        $contact = $this->resolver->resolve([
            'name' => 'Jana Nováková',
            'email' => 'jana@example.com',
        ]);

        $this->assertSame($this->organization->id, $contact->organization_id);
        $this->assertSame(
            2,
            Contact::withoutGlobalScope('organization')->whereRaw('LOWER(email) = ?', ['jana@example.com'])->count(),
            'V každé organizaci existuje vlastní kontakt.',
        );
    }

    public function test_external_id_remains_the_stronger_key_over_canonical_email(): void
    {
        $byEmail = $this->resolver->resolve([
            'name' => 'Jana Nováková',
            'email' => 'jana@example.com',
        ]);

        // Stejný (jen jinak zapsaný) e-mail, ale s external_id → e-mail se
        // nesmí použít ani k záchraně, ani k párování.
        $byExternal = $this->resolver->resolve([
            'name' => 'Jana Nováková',
            'external_id' => 'ujabka-cust-9',
            'email' => 'JANA@EXAMPLE.COM',
        ]);

        $this->assertNotSame($byEmail->id, $byExternal->id);
        $this->assertSame('ujabka-cust-9', $byExternal->external_id);
    }
}
