<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Contacts\CzechIco;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ContactType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
{
    /**
     * IČO se ukládá normalizované na 8 číslic, aby „177041“ a „00177041“
     * nevedly na dva kontakty téže firmy (viz unikátní index).
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('ico')) {
            $this->merge(['ico' => CzechIco::normalize($this->input('ico')) ?? $this->input('ico')]);
        }
    }

    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();
        $contactId = $this->route('contact')?->id;

        return [
            'type' => ['required', Rule::enum(ContactType::class)],
            'name' => ['required', 'string', 'max:255'],
            // IČO je nepovinné — odběratelem bývá i fyzická osoba bez IČO.
            'ico' => [
                'nullable', 'string', 'max:20',
                Rule::unique('contacts', 'ico')
                    ->where('organization_id', $organizationId)
                    ->ignore($contactId),
            ],
            'external_id' => [
                'nullable', 'string', 'max:255',
                Rule::unique('contacts', 'external_id')
                    ->where('organization_id', $organizationId)
                    ->ignore($contactId),
            ],
            'dic' => ['nullable', 'string', 'max:20'],
            'street' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'ico.unique' => 'Kontakt s tímto IČO už v organizaci existuje.',
            'external_id.unique' => 'Kontakt s tímto externím identifikátorem už existuje.',
        ];
    }

    public function attributes(): array
    {
        return [
            'type' => 'typ', 'name' => 'název', 'ico' => 'IČO', 'dic' => 'DIČ',
            'external_id' => 'externí identifikátor',
            'street' => 'ulice', 'city' => 'město', 'zip' => 'PSČ',
            'country' => 'země', 'email' => 'e-mail', 'phone' => 'telefon', 'note' => 'poznámka',
        ];
    }
}
