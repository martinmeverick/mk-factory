<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\BankAccount;
use App\Models\InvoiceNumberSeries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrganizationSettingsController extends Controller
{
    public function edit(): View
    {
        $organization = app(CurrentOrganization::class)->getOrFail();
        $settings = $organization->settings()->firstOrCreate([]);

        return view('settings.edit', [
            'organization' => $organization,
            'settings' => $settings,
            'bankAccounts' => BankAccount::query()->orderBy('name')->get(),
            'numberSeries' => InvoiceNumberSeries::query()->orderByDesc('year')->orderBy('name')->get(),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $organization = app(CurrentOrganization::class)->getOrFail();

        $data = $request->validate([
            'profile_name' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'ico' => ['nullable', 'string', 'max:20'],
            'dic' => ['nullable', 'string', 'max:20'],
            'street' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255'],
        ], [], [
            'name' => 'obchodní název', 'ico' => 'IČO', 'dic' => 'DIČ',
            'street' => 'ulice', 'city' => 'město', 'zip' => 'PSČ', 'country' => 'země',
            'email' => 'e-mail', 'phone' => 'telefon', 'website' => 'web',
        ]);

        $organization->update($data);

        return redirect()->route('settings.edit')->with('status', 'Údaje organizace byly uloženy.');
    }

    public function updateInvoicing(Request $request): RedirectResponse
    {
        $organization = app(CurrentOrganization::class)->getOrFail();
        $settings = $organization->settings()->firstOrCreate([]);

        $data = $request->validate([
            'vat_payer' => ['required', 'boolean'],
            'default_due_days' => ['required', 'integer', 'min:0', 'max:365'],
            'default_bank_account_id' => [
                'nullable', 'integer',
                Rule::exists('bank_accounts', 'id')->where('organization_id', $organization->id),
            ],
            'default_number_series_id' => [
                'nullable', 'integer',
                Rule::exists('invoice_number_series', 'id')->where('organization_id', $organization->id),
            ],
            'invoice_footer_text' => ['nullable', 'string', 'max:2000'],
            'invoice_default_note' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'vat_payer' => 'plátce DPH', 'default_due_days' => 'výchozí splatnost',
            'default_bank_account_id' => 'výchozí bankovní účet',
            'default_number_series_id' => 'výchozí číselná řada',
            'invoice_footer_text' => 'patička faktury', 'invoice_default_note' => 'výchozí poznámka',
        ]);

        $settings->update($data);

        return redirect()->route('settings.edit')->with('status', 'Nastavení fakturace bylo uloženo.');
    }

    public function updateLogo(Request $request): RedirectResponse
    {
        $organization = app(CurrentOrganization::class)->getOrFail();

        $request->validate(
            ['logo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:2048']],
            [
                'logo.mimes' => 'Logo musí být obrázek JPG nebo PNG.',
                'logo.max' => 'Logo může mít maximálně 2 MB.',
            ],
            ['logo' => 'logo'],
        );

        $path = $request->file('logo')->store('logos/org-'.$organization->id, 'local');

        if ($organization->logo_path && Storage::disk('local')->exists($organization->logo_path)) {
            Storage::disk('local')->delete($organization->logo_path);
        }

        $organization->update(['logo_path' => $path]);

        return redirect()->route('settings.edit')->with('status', 'Logo bylo nahráno.');
    }

    public function showLogo(): StreamedResponse
    {
        $organization = app(CurrentOrganization::class)->getOrFail();

        abort_unless(
            $organization->logo_path && Storage::disk('local')->exists($organization->logo_path),
            404,
        );

        return Storage::disk('local')->response($organization->logo_path);
    }
}
