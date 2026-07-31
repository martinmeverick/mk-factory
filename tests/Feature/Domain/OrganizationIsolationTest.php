<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\Contact;
use App\Models\IssuedInvoice;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private Contact $contactA;

    private Contact $contactB;

    private Project $projectA;

    private Project $projectB;

    private IssuedInvoice $invoiceA;

    private IssuedInvoice $invoiceB;

    protected function setUp(): void
    {
        parent::setUp();

        // Data obou organizací se zakládají BEZ aktivní organizace.
        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->contactA = Contact::factory()->create(['organization_id' => $this->orgA->id]);
        $this->contactB = Contact::factory()->create(['organization_id' => $this->orgB->id]);

        $this->projectA = Project::factory()->create(['organization_id' => $this->orgA->id]);
        $this->projectB = Project::factory()->create(['organization_id' => $this->orgB->id]);

        $this->invoiceA = IssuedInvoice::factory()->create([
            'organization_id' => $this->orgA->id,
            'contact_id' => $this->contactA->id,
        ]);
        $this->invoiceB = IssuedInvoice::factory()->create([
            'organization_id' => $this->orgB->id,
            'contact_id' => $this->contactB->id,
        ]);
    }

    private function activate(?Organization $organization): void
    {
        app(CurrentOrganization::class)->set($organization);
    }

    public function test_contacts_of_other_organization_are_invisible(): void
    {
        $this->activate($this->orgA);

        $this->assertNull(Contact::find($this->contactB->id));
        $this->assertSame(1, Contact::count());
        $this->assertTrue(Contact::first()->is($this->contactA));
    }

    public function test_issued_invoices_of_other_organization_are_invisible(): void
    {
        $this->activate($this->orgA);

        $this->assertNull(IssuedInvoice::find($this->invoiceB->id));
        $this->assertSame(1, IssuedInvoice::count());
        $this->assertTrue(IssuedInvoice::first()->is($this->invoiceA));
    }

    public function test_projects_of_other_organization_are_invisible(): void
    {
        $this->activate($this->orgA);

        $this->assertNull(Project::find($this->projectB->id));
        $this->assertSame(1, Project::count());
        $this->assertTrue(Project::first()->is($this->projectA));
    }

    public function test_organization_id_is_auto_filled_on_create(): void
    {
        $this->activate($this->orgA);

        $contact = Contact::create([
            'type' => 'customer',
            'name' => 'Nový odběratel s.r.o.',
        ]);

        $this->assertSame($this->orgA->id, $contact->organization_id);
    }

    public function test_scope_is_not_applied_without_current_organization(): void
    {
        $this->activate(null);

        $this->assertSame(2, Contact::count());
        $this->assertSame(2, Project::count());
        $this->assertSame(2, IssuedInvoice::count());
        $this->assertNotNull(Contact::find($this->contactB->id));
    }
}
