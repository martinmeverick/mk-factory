<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ContactType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ContactType::class)],
            'name' => ['required', 'string', 'max:255'],
            'ico' => ['nullable', 'string', 'max:20'],
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

    public function attributes(): array
    {
        return [
            'type' => 'typ', 'name' => 'název', 'ico' => 'IČO', 'dic' => 'DIČ',
            'street' => 'ulice', 'city' => 'město', 'zip' => 'PSČ',
            'country' => 'země', 'email' => 'e-mail', 'phone' => 'telefon', 'note' => 'poznámka',
        ];
    }
}
