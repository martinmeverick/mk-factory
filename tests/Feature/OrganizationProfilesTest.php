<?php

namespace Tests\Feature;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\InvoiceNumberSeries;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationProfilesTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->create();
        OrganizationMember::factory()->owner()->create(['user_id' => $user->id]);

        return $user;
    }

    private function profile(array $overrides = []): array
    {
        return array_replace([
            'profile_name' => 'Synthetic brand', 'name' => 'Synthetic issuer s.r.o.',
            'ico' => '12345678', 'dic' => 'CZ12345678', 'vat_payer' => '1', 'prefix' => 'TEST',
        ], $overrides);
    }

    public function test_owner_creates_independent_profile_and_switches_only_to_new_membership(): void
    {
        $user = $this->owner();
        $old = $user->organizations()->first();
        $oldSettings = OrganizationSettings::factory()->create(['organization_id' => $old->id, 'vat_payer' => true]);
        app(CurrentOrganization::class)->set($old);
        $this->actingAs($user)->post(route('organizations.store'), $this->profile(['vat_payer' => '0']))
            ->assertRedirect(route('settings.edit'));
        $created = Organization::where('profile_name', 'Synthetic brand')->firstOrFail();
        $this->assertSame($created->id, session('current_organization_id'));
        $this->assertSame($old->id, app(CurrentOrganization::class)->id());
        $this->assertDatabaseHas('organization_members', ['organization_id' => $created->id, 'user_id' => $user->id, 'role' => 'owner']);
        $settings = OrganizationSettings::withoutGlobalScopes()->where('organization_id', $created->id)->firstOrFail();
        $this->assertFalse($settings->vat_payer);
        $series = InvoiceNumberSeries::withoutGlobalScopes()->findOrFail($settings->default_number_series_id);
        $this->assertSame($created->id, $series->organization_id);
        $this->assertSame(1, $series->next_number);
        $this->assertTrue($oldSettings->fresh()->vat_payer);
        $this->assertSame('Synthetic issuer s.r.o.', $created->name);
    }

    public function test_same_ico_is_allowed_but_an_existing_series_prefix_is_rejected(): void
    {
        $user = $this->owner();
        $this->actingAs($user)->post(route('organizations.store'), $this->profile())->assertSessionHasNoErrors();
        $this->post(route('organizations.store'), $this->profile(['profile_name' => 'Other brand']))
            ->assertSessionHasErrors('prefix');
        $this->post(route('organizations.store'), $this->profile(['profile_name' => 'Other brand', 'prefix' => 'OTHER']))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Organization::where('ico', '12345678')->count());
    }

    public function test_creation_requires_existing_owner_and_vat_choice(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('organizations.create'))->assertForbidden();
        $this->post(route('organizations.store'), $this->profile())->assertForbidden();
        OrganizationMember::factory()->create(['user_id' => $user->id]);
        $this->post(route('organizations.store'), $this->profile())->assertForbidden();
        $this->actingAs($this->owner())->post(route('organizations.store'), $this->profile(['vat_payer' => '']))
            ->assertSessionHasErrors('vat_payer');
        $this->assertDatabaseMissing('organizations', ['profile_name' => 'Synthetic brand']);
    }

    public function test_failed_initialization_rolls_back_all_new_rows_and_preserves_session(): void
    {
        $user = $this->owner();
        $old = $user->organizations()->first();
        app(CurrentOrganization::class)->set($old);
        $this->withSession(['current_organization_id' => $old->id]);
        OrganizationSettings::creating(function (): void {
            throw new \RuntimeException('Synthetic initialization failure');
        });
        $this->actingAs($user)->post(route('organizations.store'), $this->profile())->assertStatus(500);
        $this->assertDatabaseMissing('organizations', ['profile_name' => 'Synthetic brand']);
        $this->assertDatabaseMissing('invoice_number_series', ['prefix' => 'TEST']);
        $this->assertSame($old->id, session('current_organization_id'));
        $this->assertSame($old->id, app(CurrentOrganization::class)->id());
    }

    public function test_selector_uses_profile_label_but_keeps_legal_issuer_visible_and_blocks_foreign_switch(): void
    {
        $user = $this->owner();
        $org = $user->organizations()->first();
        $org->update(['profile_name' => 'Synthetic internal brand', 'name' => 'Synthetic legal issuer']);
        $this->actingAs($user)->get(route('organizations.select'))
            ->assertOk()->assertSee('Synthetic internal brand')->assertSee('Synthetic legal issuer')->assertSee('Přidat fakturační profil');
        $foreign = Organization::factory()->create();
        $this->post(route('organizations.choose', $foreign))->assertForbidden();
    }
}
