<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceivedInvoiceRequest extends FormRequest
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
            'supplier_invoice_number' => ['nullable', 'string', 'max:50'],
            'variable_symbol' => ['nullable', 'digits_between:1,10'],
            'issue_date' => ['nullable', 'date'],
            'received_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'total' => ['required', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'],
            'vat' => ['nullable', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'],
            'note' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'total.regex' => 'Částka musí být číslo (max. 2 desetinná místa).',
            'vat.regex' => 'DPH musí být číslo (max. 2 desetinná místa).',
            'attachment.mimes' => 'Příloha musí být PDF nebo obrázek (JPG/PNG).',
            'attachment.max' => 'Příloha může mít maximálně 10 MB.',
        ];
    }

    public function attributes(): array
    {
        return [
            'contact_id' => 'dodavatel', 'project_id' => 'projekt',
            'supplier_invoice_number' => 'číslo faktury dodavatele',
            'variable_symbol' => 'variabilní symbol', 'issue_date' => 'datum vystavení',
            'received_date' => 'datum přijetí', 'due_date' => 'datum splatnosti',
            'total' => 'částka celkem', 'vat' => 'z toho DPH',
            'note' => 'poznámka', 'attachment' => 'příloha',
        ];
    }
}
