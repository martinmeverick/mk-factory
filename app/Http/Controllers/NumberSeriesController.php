<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\InvoiceNumberSeries;
use App\Models\IssuedInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NumberSeriesController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        InvoiceNumberSeries::create($this->validated($request));

        return redirect()->route('settings.edit')->with('status', 'Číselná řada byla přidána.');
    }

    public function update(Request $request, InvoiceNumberSeries $series): RedirectResponse
    {
        $series->update($this->validated($request, $series));

        return redirect()->route('settings.edit')->with('status', 'Číselná řada byla upravena.');
    }

    public function destroy(InvoiceNumberSeries $series): RedirectResponse
    {
        if (IssuedInvoice::query()->where('number_series_id', $series->id)->exists()) {
            return redirect()->route('settings.edit')
                ->with('error', 'Řadu nelze smazat — byly z ní vystaveny faktury.');
        }

        $series->delete();

        return redirect()->route('settings.edit')->with('status', 'Číselná řada byla smazána.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?InvoiceNumberSeries $series = null): array
    {
        $prefix = strtoupper((string) $request->input('prefix', ''));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]*$/'],
            'year' => [
                'required', 'integer', 'min:2000', 'max:2100',
                // Kombinace (organizace, prefix, rok) musí být unikátní.
                Rule::unique('invoice_number_series', 'year')
                    ->ignore($series?->id)
                    ->where('organization_id', app(CurrentOrganization::class)->id())
                    ->where('prefix', $prefix),
            ],
            'next_number' => ['required', 'integer', 'min:1'],
            'number_format' => ['required', 'string', 'max:50', 'regex:/\{NUMBER:\d+\}/'],
        ], [
            'number_format.regex' => 'Formát musí obsahovat token {NUMBER:n}, např. {PREFIX}{YEAR}{NUMBER:4}.',
            'year.unique' => 'Řada se stejným prefixem a rokem už existuje.',
        ], [
            'name' => 'název', 'prefix' => 'prefix', 'year' => 'rok',
            'next_number' => 'další číslo', 'number_format' => 'formát čísla',
        ]);

        $data['prefix'] = $prefix;

        return $data;
    }
}
