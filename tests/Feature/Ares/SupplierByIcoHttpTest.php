<?php

declare(strict_types=1);

namespace Tests\Feature\Ares;

use App\Domain\Ares\AresClient;
use App\Domain\Ares\AresSubject;
use App\Domain\Ares\AresUnavailable;
use App\Enums\ContactType;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\ReceivedInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Evidence přijaté faktury s dodavatelem, který v systému ještě není.
 */
class SupplierByIcoHttpTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create();
        $this->user->organizations()->attach($this->organization, ['role' => 'member']);

        $this->bindAres($this->skodaSubject());
    }

    private function actingInOrganization(): self
    {
        return $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->organization->id]);
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

    private function bindAres(?AresSubject $subject, bool $failing = false): void
    {
        $this->app->instance(AresClient::class, new class($subject, $failing) implements AresClient
        {
            public function __construct(private readonly ?AresSubject $subject, private readonly bool $failing)
            {
            }

            public function findByIco(string $ico): ?AresSubject
            {
                if ($this->failing) {
                    throw AresUnavailable::because('test');
                }

                return $this->subject;
            }

            public function searchByName(string $name, int $limit = 10): array
            {
                if ($this->failing) {
                    throw AresUnavailable::because('test');
                }

                return $this->subject === null ? [] : [$this->subject];
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(array $override = []): array
    {
        return array_merge([
            'supplier_mode' => 'ico',
            'supplier_ico' => '00177041',
            'received_date' => '2026-08-04',
            'total' => '12100.00',
        ], $override);
    }

    public function test_creates_supplier_contact_while_saving_the_invoice(): void
    {
        $this->actingInOrganization()
            ->post(route('received.store'), $this->invoicePayload())
            ->assertRedirect();

        $contact = Contact::query()->where('ico', '00177041')->firstOrFail();

        $this->assertSame('Škoda Auto a.s.', $contact->name);
        $this->assertSame(ContactType::Supplier, $contact->type);
        $this->assertSame($contact->id, ReceivedInvoice::query()->firstOrFail()->contact_id);
    }

    public function test_second_invoice_reuses_the_same_supplier(): void
    {
        $this->actingInOrganization()->post(route('received.store'), $this->invoicePayload());
        $this->actingInOrganization()->post(route('received.store'), $this->invoicePayload([
            'supplier_ico' => '177041', // stejná firma bez vodicích nul
        ]));

        $this->assertSame(1, Contact::query()->where('ico', '00177041')->count());
        $this->assertSame(2, ReceivedInvoice::query()->count());
    }

    public function test_existing_supplier_can_still_be_picked_from_the_list(): void
    {
        $supplier = Contact::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => ContactType::Supplier,
        ]);

        $this->actingInOrganization()
            ->post(route('received.store'), $this->invoicePayload([
                'supplier_mode' => 'existing',
                'contact_id' => $supplier->id,
                'supplier_ico' => null,
            ]))
            ->assertRedirect();

        $this->assertSame($supplier->id, ReceivedInvoice::query()->firstOrFail()->contact_id);
    }

    public function test_invalid_ico_is_reported_on_the_field(): void
    {
        $this->actingInOrganization()
            ->post(route('received.store'), $this->invoicePayload(['supplier_ico' => '12345678']))
            ->assertSessionHasErrors('supplier_ico');

        $this->assertSame(0, ReceivedInvoice::query()->count());
    }

    public function test_missing_supplier_selection_is_rejected(): void
    {
        $this->actingInOrganization()
            ->post(route('received.store'), $this->invoicePayload([
                'supplier_mode' => 'existing',
                'supplier_ico' => null,
            ]))
            ->assertSessionHasErrors('contact_id');
    }

    public function test_registry_outage_is_reported_and_nothing_is_saved(): void
    {
        $this->bindAres(null, failing: true);

        $this->actingInOrganization()
            ->post(route('received.store'), $this->invoicePayload())
            ->assertSessionHasErrors('supplier_ico');

        $this->assertSame(0, ReceivedInvoice::query()->count());
        $this->assertSame(0, Contact::query()->count());
    }

    public function test_manual_name_lets_the_invoice_through_during_an_outage(): void
    {
        $this->bindAres(null, failing: true);

        $this->actingInOrganization()
            ->post(route('received.store'), $this->invoicePayload([
                'supplier_name' => 'Dodavatel bez registru s.r.o.',
            ]))
            ->assertRedirect();

        $this->assertSame(
            'Dodavatel bez registru s.r.o.',
            Contact::query()->where('ico', '00177041')->firstOrFail()->name,
        );
    }

    public function test_supplier_from_another_organization_is_not_reused(): void
    {
        $otherOrganization = Organization::factory()->create();
        Contact::withoutGlobalScope('organization')->create([
            'organization_id' => $otherOrganization->id,
            'ico' => '00177041',
            'name' => 'Cizí kopie',
            'type' => ContactType::Supplier,
        ]);

        $this->actingInOrganization()->post(route('received.store'), $this->invoicePayload());

        $mine = Contact::query()->where('ico', '00177041')->firstOrFail();

        $this->assertSame($this->organization->id, $mine->organization_id);
        $this->assertSame('Škoda Auto a.s.', $mine->name);
    }

    // ---------- lookup endpoint ----------

    public function test_lookup_returns_subject_for_valid_ico(): void
    {
        $this->actingInOrganization()
            ->getJson(route('ares.show', ['ico' => '00177041']))
            ->assertOk()
            ->assertJsonPath('subject.name', 'Škoda Auto a.s.')
            ->assertJsonPath('subject.vat_payer', true)
            ->assertJsonPath('subject.zip', '293 01');
    }

    public function test_lookup_rejects_invalid_checksum_without_calling_registry(): void
    {
        $this->actingInOrganization()
            ->getJson(route('ares.show', ['ico' => '12345678']))
            ->assertStatus(422);
    }

    public function test_lookup_reports_missing_subject(): void
    {
        $this->bindAres(null);

        $this->actingInOrganization()
            ->getJson(route('ares.show', ['ico' => '00177041']))
            ->assertStatus(404);
    }

    public function test_lookup_reports_registry_outage(): void
    {
        $this->bindAres(null, failing: true);

        $this->actingInOrganization()
            ->getJson(route('ares.show', ['ico' => '00177041']))
            ->assertStatus(503);
    }

    public function test_lookup_requires_authentication(): void
    {
        // Webová routa — host je přesměrován na přihlášení.
        $this->get(route('ares.show', ['ico' => '00177041']))
            ->assertRedirect(route('login'));
    }

    public function test_lookup_is_not_reachable_without_an_active_organization(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('ares.show', ['ico' => '00177041']))
            ->assertRedirect(route('organizations.select'));
    }
}
