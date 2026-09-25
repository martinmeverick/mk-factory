<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Money\UsedGoodsMargin;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InvoiceRecipientMode;
use App\Enums\VatRegime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IssuedInvoiceRequest extends FormRequest
{
    /**
     * Pole fyzické osoby, která formulář přijímá. Cokoli jiného v `person`
     * (ico, dic, type, organization_id, id, …) je chyba — nikdy se tiše
     * nepoužije ani neignoruje.
     */
    public const array PERSON_FIELDS = ['name', 'street', 'city', 'zip', 'country', 'email'];

    /**
     * Klíče, které by z ručního zadání osoby mohly podstrčit firemní
     * identitu nebo tenant/identifikátor; hlásí se jmenovitě.
     */
    private const array PERSON_FORBIDDEN_FIELDS = ['ico', 'dic', 'external_id', 'type', 'organization_id', 'id'];

    /**
     * Způsob zadání odběratele. Chybějící/prázdná hodnota = výběr z kontaktů
     * (dosavadní chování); neplatná hodnota vrací null a validuje se
     * pravidlem `recipient_mode` (pravidla pak platí pro režim kontaktů).
     */
    public function recipientMode(): ?InvoiceRecipientMode
    {
        $value = $this->input('recipient_mode');

        if ($value === null || $value === '') {
            return InvoiceRecipientMode::Existing;
        }

        return is_string($value) ? InvoiceRecipientMode::tryFrom($value) : null;
    }

    public function isManualRecipient(): bool
    {
        return $this->recipientMode() === InvoiceRecipientMode::Manual;
    }

    /**
     * Validované atributy nové fyzické osoby — POUZE whitelist polí
     * formuláře. Organizaci a typ doplňuje controller, IČO/DIČ/external_id
     * zůstávají null.
     *
     * @return array{name: string, street: string, city: string, zip: string, country: string, email: ?string}
     */
    public function personAttributes(): array
    {
        $person = $this->validated('person');

        if (! $this->isManualRecipient() || ! is_array($person)) {
            throw new \LogicException('Atributy osoby jsou k dispozici jen v režimu ručního zadání odběratele.');
        }

        return [
            'name' => (string) $person['name'],
            'street' => (string) $person['street'],
            'city' => (string) $person['city'],
            'zip' => (string) $person['zip'],
            'country' => (string) $person['country'],
            'email' => isset($person['email']) && $person['email'] !== '' ? (string) $person['email'] : null,
        ];
    }

    /**
     * Kód země se normalizuje na velká písmena („cz“ → „CZ“); jiné tvary
     * než řetězec se nechají spadnout na validaci (žádný TypeError).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('discount_value'))) {
            $this->merge(['discount_value' => str_replace(',', '.', trim($this->input('discount_value')))]);
        }
        $person = $this->input('person');

        if (! $this->isManualRecipient() || ! is_array($person)) {
            return;
        }

        if (is_string($person['country'] ?? null)) {
            $person['country'] = strtoupper(trim($person['country']));
            $this->merge(['person' => $person]);
        }
    }

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
        $manual = $this->isManualRecipient();

        $rules = [
            'discount_type' => ['nullable', 'string', Rule::in(['none', 'percent', 'fixed'])],
            'discount_value' => ['nullable', 'required_if:discount_type,percent,fixed', 'regex:/^\d{1,17}(\.\d{1,2})?$/D'],
            // Chybějící = výběr z kontaktů (zpětná kompatibilita); neplatná hodnota = chyba.
            'recipient_mode' => ['nullable', 'string', Rule::enum(InvoiceRecipientMode::class)],
            'contact_id' => $manual
                // Ruční zadání osoby a současně vybraný kontakt si odporují.
                ? ['prohibited']
                : [
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

        if ($manual) {
            // Fyzická osoba: jméno a adresa povinné, žádné IČO/DIČ. Země je
            // ISO kód (normalizuje se na velká písmena v prepareForValidation).
            $rules['person'] = ['required', 'array'];
            $rules['person.name'] = ['required', 'string', 'max:255'];
            $rules['person.street'] = ['required', 'string', 'max:255'];
            $rules['person.city'] = ['required', 'string', 'max:255'];
            $rules['person.zip'] = ['required', 'string', 'max:20'];
            $rules['person.country'] = ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'];
            $rules['person.email'] = ['nullable', 'string', 'email', 'max:255'];

            foreach (self::PERSON_FORBIDDEN_FIELDS as $field) {
                $rules["person.{$field}"] = ['prohibited'];
            }
        } else {
            // Režim kontaktů: pole osoby nesmí nést hodnotu (prázdná pole
            // formuláře bez JS projdou — jsou převedena na null).
            $rules['person'] = ['nullable', 'array'];
            $rules['person.*'] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * Zvláštní režim smí zvolit jen organizace nastavená jako plátce DPH;
     * v ručním zadání osoby nesmí být žádné jiné než whitelistované klíče.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('discount_value') || $validator->errors()->has('discount_type')) {
                    return;
                }
                $type = $this->input('discount_type') ?: 'none';
                $value = (string) ($this->input('discount_value') ?? '0');
                if (($type === 'percent' && bccomp($value, '100', 2) > 0)
                    || ($type === 'none' && bccomp($value, '0', 2) !== 0)) {
                    $validator->errors()->add('discount_value', 'Zadejte slevu od 0 do 100 %, nebo nulovou hodnotu pro volbu Bez slevy.');
                }
            },
            function (Validator $validator): void {
                $person = $this->input('person');

                if (! $this->isManualRecipient() || ! is_array($person)) {
                    return;
                }

                $unexpected = array_diff(array_keys($person), self::PERSON_FIELDS);

                if ($unexpected !== []) {
                    $validator->errors()->add(
                        'person',
                        'Nepovolená pole fyzické osoby: '.implode(', ', array_map('strval', $unexpected)).'.',
                    );
                }
            },
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
            'recipient_mode.Illuminate\Validation\Rules\Enum' => 'Neznámý způsob zadání odběratele.',
            'recipient_mode.string' => 'Neznámý způsob zadání odběratele.',
            'contact_id.prohibited' => 'Při ručním zadání fyzické osoby se odběratel z kontaktů nevybírá.',
            'person.required' => 'Zadejte údaje fyzické osoby.',
            'person.array' => 'Údaje fyzické osoby mají neplatný tvar.',
            'person.*.prohibited' => 'Údaje fyzické osoby se zadávají jen při volbě „Zadat fyzickou osobu“.',
            'person.ico.prohibited' => 'U fyzické osoby se IČO nezadává.',
            'person.dic.prohibited' => 'U fyzické osoby se DIČ nezadává.',
            'person.country.size' => 'Země musí být dvoupísmenný kód (např. CZ).',
            'person.country.regex' => 'Země musí být dvoupísmenný kód (např. CZ).',
            'margin_vat_rate.required' => 'Zvolte interní sazbu DPH z přirážky.',
            'margin_vat_rate.in' => 'Nepodporovaná sazba DPH z přirážky (podporováno: '.implode(', ', UsedGoodsMargin::rateOptions()).' %).',
            'margin_vat_rate.prohibited' => 'Sazba DPH z přirážky se zadává jen ve zvláštním režimu - použité zboží.',
        ];
    }

    public function attributes(): array
    {
        return [
            'recipient_mode' => 'způsob zadání odběratele',
            'contact_id' => 'odběratel', 'project_id' => 'projekt',
            'person' => 'fyzická osoba', 'person.name' => 'jméno a příjmení',
            'person.street' => 'ulice a číslo', 'person.city' => 'město', 'person.zip' => 'PSČ',
            'person.country' => 'země', 'person.email' => 'e-mail',
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
