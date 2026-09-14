<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Money\UsedGoodsMargin;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\VatRegime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IssuedInvoiceRequest extends FormRequest
{
    /**
     * Režim DPH z požadavku. Chybějící/neplatná hodnota = běžný režim
     * (dosavadní chování); samotná hodnota se validuje pravidlem `vat_regime`.
     */
    public function vatRegime(): VatRegime
    {
        $value = $this->input('vat_regime');

        if (! is_string($value)) {
            return VatRegime::Standard;
        }

        return VatRegime::tryFrom($value) ?? VatRegime::Standard;
    }

    public function isUsedGoodsMargin(): bool
    {
        return $this->vatRegime() === VatRegime::UsedGoodsMargin;
    }

    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();
        $margin = $this->isUsedGoodsMargin();

        $rules = [
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
            // Chybějící = běžný režim (zpětná kompatibilita); neplatná hodnota = chyba.
            'vat_regime' => ['nullable', Rule::enum(VatRegime::class)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'regex:/^\d{1,9}([.,]\d{1,3})?$/'],
            'items.*.unit' => ['required', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'regex:/^-?\d{1,10}([.,]\d{1,2})?$/'],
            'items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];

        if ($margin) {
            // Zvláštní režim - použité zboží: explicitní interní sazba, celé
            // kusy, nezáporná konečná prodejní cena, povinná pořizovací cena,
            // žádná běžná sazba DPH u položek.
            $rules['margin_vat_rate'] = ['required', 'string', Rule::in(UsedGoodsMargin::rateOptions())];
            $rules['items.*.quantity'] = ['required', 'integer', 'min:1', 'max:999999999'];
            $rules['items.*.unit_price'] = ['required', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'];
            $rules['items.*.acquisition_unit_price'] = ['required', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'];
            $rules['items.*.vat_rate'] = ['prohibited'];
        } else {
            // Běžný režim: pole zvláštního režimu nesmí být odeslána (žádná
            // tichá reinterpretace cen při přepnutí režimu).
            $rules['margin_vat_rate'] = ['prohibited'];
            $rules['items.*.acquisition_unit_price'] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * Zvláštní režim smí zvolit jen organizace nastavená jako plátce DPH.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->isUsedGoodsMargin()) {
                    return;
                }

                $settings = app(CurrentOrganization::class)->getOrFail()->settings;

                if (! (bool) $settings?->vat_payer) {
                    $validator->errors()->add(
                        'vat_regime',
                        'Zvláštní režim - použité zboží lze zvolit jen u organizace nastavené jako plátce DPH.',
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Faktura musí obsahovat alespoň jednu položku.',
            'items.min' => 'Faktura musí obsahovat alespoň jednu položku.',
            'items.*.quantity.regex' => 'Množství musí být číslo (max. 3 desetinná místa).',
            'items.*.quantity.integer' => 'Ve zvláštním režimu - použité zboží musí být množství v celých kusech.',
            'items.*.quantity.min' => 'Ve zvláštním režimu - použité zboží musí být množství alespoň 1 kus.',
            'items.*.unit_price.regex' => $this->isUsedGoodsMargin()
                ? 'Konečná prodejní cena musí být nezáporná částka (max. 2 desetinná místa).'
                : 'Cena musí být částka (max. 2 desetinná místa).',
            'items.*.acquisition_unit_price.required' => 'Ve zvláštním režimu - použité zboží je nutné zadat pořizovací cenu každé položky.',
            'items.*.acquisition_unit_price.regex' => 'Pořizovací cena musí být nezáporná částka (max. 2 desetinná místa).',
            'items.*.acquisition_unit_price.prohibited' => 'Pořizovací cena se zadává jen ve zvláštním režimu - použité zboží.',
            'items.*.vat_rate.prohibited' => 'Ve zvláštním režimu - použité zboží se sazba DPH u položek nezadává (DPH se počítá z přirážky).',
            'vat_regime.Illuminate\Validation\Rules\Enum' => 'Neznámý režim DPH.',
            'margin_vat_rate.required' => 'Zvolte interní sazbu DPH z přirážky.',
            'margin_vat_rate.in' => 'Nepodporovaná sazba DPH z přirážky (podporováno: '.implode(', ', UsedGoodsMargin::rateOptions()).' %).',
            'margin_vat_rate.prohibited' => 'Sazba DPH z přirážky se zadává jen ve zvláštním režimu - použité zboží.',
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
            'vat_regime' => 'režim DPH', 'margin_vat_rate' => 'sazba DPH z přirážky',
            'items' => 'položky',
            'items.*.description' => 'popis', 'items.*.quantity' => 'množství',
            'items.*.unit' => 'jednotka', 'items.*.unit_price' => 'cena za jednotku',
            'items.*.vat_rate' => 'sazba DPH',
            'items.*.acquisition_unit_price' => 'pořizovací cena za jednotku',
        ];
    }
}
