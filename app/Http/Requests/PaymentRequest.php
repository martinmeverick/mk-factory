<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'],
            'paid_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['amount.regex' => 'Částka musí být kladné číslo (max. 2 desetinná místa).'];
    }

    public function attributes(): array
    {
        return ['amount' => 'částka', 'paid_on' => 'datum úhrady', 'note' => 'poznámka'];
    }
}
