<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\MemberRole;
use App\Models\InvoiceNumberSeries;
use App\Models\Organization;
use App\Models\OrganizationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrganizationSelectController extends Controller
{
    public function index(Request $request): View
    {
        return view('organizations.select', [
            'organizations' => $request->user()->organizations()->orderBy('name')->get(),
            'canCreate' => $this->canCreate($request),
        ]);
    }

    private function canCreate(Request $request): bool
    {
        return $request->user()->organizations()->wherePivot('role', MemberRole::Owner->value)->exists();
    }

    public function create(Request $request): View
    {
        abort_unless($this->canCreate($request), 403);

        return view('organizations.create');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canCreate($request), 403);
        $data = $request->validate([
            'profile_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'ico' => ['nullable', 'string', 'max:20'],
            'dic' => ['nullable', 'string', 'max:20'],
            'vat_payer' => ['required', 'boolean'],
            'prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9-]+$/'],
        ], [], [
            'profile_name' => 'název profilu', 'name' => 'právní název vystavovatele',
            'ico' => 'IČO', 'dic' => 'DIČ', 'vat_payer' => 'plátce DPH', 'prefix' => 'prefix číselné řady',
        ]);

        $organization = DB::transaction(function () use ($request, $data): Organization {
            // Serialize creation by this owner and matching existing issuers.
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $issuerIds = empty($data['ico']) ? [] : Organization::query()
                ->where('ico', $data['ico'])->orderBy('id')->lockForUpdate()->pluck('id')->all();
            if ($issuerIds !== [] && InvoiceNumberSeries::withoutGlobalScopes()
                ->whereIn('organization_id', $issuerIds)->where('prefix', $data['prefix'])->exists()) {
                throw ValidationException::withMessages(['prefix' => 'Zvolte pro tento profil jiný prefix číselné řady.']);
            }
            $organization = Organization::create(collect($data)->only(['profile_name', 'name', 'ico', 'dic'])->all());
            $organization->users()->attach($request->user()->id, ['role' => MemberRole::Owner->value]);
            $current = app(CurrentOrganization::class);
            $previous = $current->get();
            $current->set($organization);
            try {
                $series = InvoiceNumberSeries::create([
                    'organization_id' => $organization->id,
                    'name' => 'Vydané faktury', 'prefix' => $data['prefix'],
                    'year' => (int) date('Y'), 'next_number' => 1,
                    'number_format' => '{PREFIX}{YEAR}{NUMBER:4}',
                ]);
                OrganizationSettings::create([
                    'organization_id' => $organization->id, 'vat_payer' => $data['vat_payer'],
                    'default_due_days' => 14, 'default_number_series_id' => $series->id,
                ]);
            } finally {
                $current->set($previous);
            }

            return $organization;
        });
        $request->session()->put('current_organization_id', $organization->id);

        return redirect()->route('settings.edit')->with('status', 'Profil byl vytvořen. Doplňte adresu a bankovní účet před vystavením faktury.');
    }

    public function select(Request $request, Organization $organization): RedirectResponse
    {
        abort_unless($request->user()->belongsToOrganization($organization), 403);

        $request->session()->put('current_organization_id', $organization->id);

        return redirect()->route('dashboard');
    }
}
