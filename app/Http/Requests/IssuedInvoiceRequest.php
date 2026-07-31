<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssuedInvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();

        return [
            'contact_id' => [
                'required', 'integer',
                Rule::exists('contacts', 'id')->where('organization_id', $organizationId),
            ],
            'project_id' => [
                'nullable', 'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'bank_account_id' => [
                'nullable', 'integer',
                Rule::exists('bank_accounts', 'id')->where('organization_id', $organizationId),
            ],
            'number_series_id' => [
                'required', 'integer',
                Rule::exists('invoice_number_series', 'id')->where('organization_id', $organizationId),
            ],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'tax_date' => ['nullable', 'date'],
            'variable_symbol' => ['nullable', 'digits_between:1,10'],
            'note' => ['nullable', 'string', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'regex:/^\d{1,9}([.,]\d{1,3})?$/'],
            'items.*.unit' => ['required', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'regex:/^-?\d{1,10}([.,]\d{1,2})?$/'],
            'items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Faktura musí obsahovat alespoň jednu položku.',
            'items.min' => 'Faktura musí obsahovat alespoň jednu položku.',
            'items.*.quantity.regex' => 'Množství musí být číslo (max. 3 desetinná místa).',
            'items.*.unit_price.regex' => 'Cena musí být částka (max. 2 desetinná místa).',
        ];
    }

    public function attributes(): array
    {
        return [
            'contact_id' => 'odběratel', 'project_id' => 'projekt',
            'bank_account_id' => 'bankovní účet', 'number_series_id' => 'číselná řada',
            'issue_date' => 'datum vystavení', 'due_date' => 'datum splatnosti',
            'tax_date' => 'DUZP', 'variable_symbol' => 'variabilní symbol',
            'note' => 'poznámka', 'internal_note' => 'interní poznámka',
            'items' => 'položky',
            'items.*.description' => 'popis', 'items.*.quantity' => 'množství',
            'items.*.unit' => 'jednotka', 'items.*.unit_price' => 'cena za jednotku',
            'items.*.vat_rate' => 'sazba DPH',
        ];
    }
}
