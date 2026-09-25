@php
    /** @var ?\App\Models\IssuedInvoice $invoice */
    use App\Domain\Money\Money;
    use App\Domain\Money\UsedGoodsMargin;
    use App\Enums\InvoiceRecipientMode;
    use App\Enums\VatRegime;
    use App\Http\Requests\IssuedInvoiceRequest;

    // Způsob zadání odběratele: old() > výběr z kontaktů. Koncept založený
    // s ručně zadanou osobou se edituje jako běžný kontakt (osoba už je
    // v kontaktech) — nová osoba vzniká jen po explicitním přepnutí.
    $oldMode = old('recipient_mode');
    $recipientMode = is_string($oldMode) ? (InvoiceRecipientMode::tryFrom($oldMode) ?? InvoiceRecipientMode::Existing) : InvoiceRecipientMode::Existing;
    $isManualRecipient = $recipientMode === InvoiceRecipientMode::Manual;
    $oldPerson = old('person');
    $oldPerson = is_array($oldPerson) ? $oldPerson : [];
    $personValue = fn (string $key, string $default = ''): string => is_string($oldPerson[$key] ?? null) ? $oldPerson[$key] : $default;

    // Režim DPH: old() > uložený koncept > běžný režim. Neplátce má vždy běžný režim.
    $storedRegime = $invoice?->vatRegime()->value ?? VatRegime::Standard->value;
    $oldRegime = old('vat_regime', $storedRegime);
    $currentRegime = is_string($oldRegime) && VatRegime::tryFrom($oldRegime) !== null ? $oldRegime : $storedRegime;
    if (! $vatPayer) {
        $currentRegime = VatRegime::Standard->value;
    }
    $isMargin = $currentRegime === VatRegime::UsedGoodsMargin->value;
    $marginDraftOfNonPayer = ! $vatPayer && $storedRegime === VatRegime::UsedGoodsMargin->value;

    $storedMarginRate = $invoice?->margin_vat_rate !== null
        ? UsedGoodsMargin::rateToOption((string) $invoice->margin_vat_rate)
        : UsedGoodsMargin::rateOptions()[0];
    $oldMarginRate = old('margin_vat_rate', $storedMarginRate);
    $currentMarginRate = is_string($oldMarginRate) ? $oldMarginRate : $storedMarginRate;

    $oldItems = old('items');
    if ($oldItems === null && $invoice) {
        $oldItems = $invoice->items->sortBy('position')->values()->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.'),
            'unit' => $item->unit,
            'unit_price' => Money::fromMinor((int) $item->unit_price_minor)->toDecimalString(),
            'vat_rate' => $item->vat_rate !== null ? (string) (int) $item->vat_rate : null,
            'acquisition_unit_price' => $item->acquisition_unit_price_minor !== null
                ? Money::fromMinor((int) $item->acquisition_unit_price_minor)->toDecimalString()
                : '',
        ])->all();
    }
    $oldItems = $oldItems ?: [['description' => '', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '', 'vat_rate' => '21', 'acquisition_unit_price' => '']];
    $discountType = old('discount_type', $invoice?->discount_type ?? 'none');
    $discountType = is_string($discountType) && in_array($discountType, ['none', 'percent', 'fixed'], true) ? $discountType : 'none';
    $discountValue = old('discount_value', $invoice?->discount_value ?? '0');
    $discountValue = is_scalar($discountValue) ? (string) $discountValue : '';
@endphp

@if ($marginDraftOfNonPayer)
    <div class="flash flash-error" role="alert">
        Tento koncept je ve zvláštním režimu - použité zboží, ale organizace už není nastavena jako plátce DPH.
        Uložením se koncept převede do běžného režimu neplátce (pořizovací ceny se zahodí) — zkontrolujte ceny položek.
    </div>
@endif

<div class="form-grid">
    <fieldset class="field span-2 field-group" aria-describedby="recipient_mode_hint">
        <legend>Odběratel *</legend>
        <div class="inline-form">
            @foreach (InvoiceRecipientMode::cases() as $mode)
                <div class="field-checkbox">
                    <label for="recipient_mode_{{ $mode->value }}">
                        <input type="radio" id="recipient_mode_{{ $mode->value }}" name="recipient_mode"
                               value="{{ $mode->value }}" @checked($recipientMode === $mode)>
                        {{ $mode->label() }}
                    </label>
                </div>
            @endforeach
        </div>
        <p class="field-hint" id="recipient_mode_hint">
            Fyzickou osobu (bez IČO a DIČ) zadáte přímo zde — uloží se do kontaktů jako odběratel
            společně s konceptem, nemusíte ji zakládat předem.
        </p>
        @error('recipient_mode')<p class="field-error">{{ $message }}</p>@enderror
    </fieldset>

    <div class="field span-2" data-recipient="{{ InvoiceRecipientMode::Existing->value }}" @if ($isManualRecipient) hidden @endif>
        <label for="contact_id">Odběratel z kontaktů *</label>
        <select id="contact_id" name="contact_id" required @disabled($isManualRecipient)>
            <option value="">— vyberte —</option>
            @foreach ($customers as $customer)
                <option value="{{ $customer->id }}" @selected((int) old('contact_id', $invoice?->contact_id) === $customer->id)>
                    {{ $customer->name }}{{ $customer->ico ? ' (IČO '.$customer->ico.')' : '' }}
                </option>
            @endforeach
        </select>
        @error('contact_id')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field span-2" data-recipient="{{ InvoiceRecipientMode::Manual->value }}" @if (! $isManualRecipient) hidden @endif>
        <label for="person_name">Jméno a příjmení *</label>
        <input type="text" id="person_name" name="person[name]" maxlength="255" autocomplete="name"
               value="{{ $personValue('name') }}" required @disabled(! $isManualRecipient)>
        @error('person')<p class="field-error">{{ $message }}</p>@enderror
        @error('person.name')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field" data-recipient="{{ InvoiceRecipientMode::Manual->value }}" @if (! $isManualRecipient) hidden @endif>
        <label for="person_street">Ulice a číslo *</label>
        <input type="text" id="person_street" name="person[street]" maxlength="255" autocomplete="street-address"
               value="{{ $personValue('street') }}" required @disabled(! $isManualRecipient)>
        @error('person.street')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field" data-recipient="{{ InvoiceRecipientMode::Manual->value }}" @if (! $isManualRecipient) hidden @endif>
        <label for="person_city">Město *</label>
        <input type="text" id="person_city" name="person[city]" maxlength="255" autocomplete="address-level2"
               value="{{ $personValue('city') }}" required @disabled(! $isManualRecipient)>
        @error('person.city')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field" data-recipient="{{ InvoiceRecipientMode::Manual->value }}" @if (! $isManualRecipient) hidden @endif>
        <label for="person_zip">PSČ *</label>
        <input type="text" id="person_zip" name="person[zip]" maxlength="20" autocomplete="postal-code"
               value="{{ $personValue('zip') }}" required @disabled(! $isManualRecipient)>
        @error('person.zip')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field" data-recipient="{{ InvoiceRecipientMode::Manual->value }}" @if (! $isManualRecipient) hidden @endif>
        <label for="person_country">Země (kód) *</label>
        <input type="text" id="person_country" name="person[country]" maxlength="2" autocomplete="country"
               autocapitalize="characters" value="{{ $personValue('country', 'CZ') }}" required @disabled(! $isManualRecipient)
               aria-describedby="person_country_hint">
        <p class="field-hint" id="person_country_hint">Dvoupísmenný kód země, výchozí CZ.</p>
        @error('person.country')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field" data-recipient="{{ InvoiceRecipientMode::Manual->value }}" @if (! $isManualRecipient) hidden @endif>
        <label for="person_email">E-mail</label>
        <input type="email" id="person_email" name="person[email]" maxlength="255" autocomplete="email"
               value="{{ $personValue('email') }}" @disabled(! $isManualRecipient)>
        @error('person.email')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="project_id">Projekt</label>
        <select id="project_id" name="project_id">
            <option value="">— žádný —</option>
            @foreach ($projects as $project)
                <option value="{{ $project->id }}" @selected((int) old('project_id', $invoice?->project_id) === $project->id)>
                    {{ $project->name }}
                </option>
            @endforeach
        </select>
        @error('project_id')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="number_series_id">Číselná řada *</label>
        <select id="number_series_id" name="number_series_id" required>
            <option value="">— vyberte —</option>
            @foreach ($numberSeries as $series)
                <option value="{{ $series->id }}"
                        @selected((int) old('number_series_id', $invoice?->number_series_id ?? ($defaults['number_series_id'] ?? null)) === $series->id)>
                    {{ $series->name }} ({{ $series->prefix }}{{ $series->year }})
                </option>
            @endforeach
        </select>
        @error('number_series_id')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="bank_account_id">Bankovní účet</label>
        <select id="bank_account_id" name="bank_account_id">
            <option value="">— žádný —</option>
            @foreach ($bankAccounts as $account)
                <option value="{{ $account->id }}"
                        @selected((int) old('bank_account_id', $invoice?->bank_account_id ?? ($defaults['bank_account_id'] ?? null)) === $account->id)>
                    {{ $account->name }} ({{ $account->account_number }}/{{ $account->bank_code }})
                </option>
            @endforeach
        </select>
        @error('bank_account_id')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="variable_symbol">Variabilní symbol</label>
        <input type="text" id="variable_symbol" name="variable_symbol" inputmode="numeric"
               value="{{ old('variable_symbol', $invoice?->variable_symbol) }}"
               aria-describedby="vs_hint">
        <p class="field-hint" id="vs_hint">Ponechte prázdné — doplní se z čísla faktury při vystavení.</p>
        @error('variable_symbol')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="issue_date">Datum vystavení *</label>
        <input type="date" id="issue_date" name="issue_date"
               value="{{ old('issue_date', $invoice?->issue_date?->toDateString() ?? ($defaults['issue_date'] ?? '')) }}" required>
        @error('issue_date')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="due_date">Datum splatnosti *</label>
        <input type="date" id="due_date" name="due_date"
               value="{{ old('due_date', $invoice?->due_date?->toDateString() ?? ($defaults['due_date'] ?? '')) }}" required>
        @error('due_date')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    @if ($vatPayer)
        <div class="field">
            <label for="tax_date">DUZP</label>
            <input type="date" id="tax_date" name="tax_date"
                   value="{{ old('tax_date', $invoice?->tax_date?->toDateString()) }}">
            @error('tax_date')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label for="vat_regime">Režim DPH *</label>
            <select id="vat_regime" name="vat_regime" aria-describedby="vat_regime_hint">
                @foreach (VatRegime::cases() as $regime)
                    <option value="{{ $regime->value }}" @selected($currentRegime === $regime->value)>{{ $regime->label() }}</option>
                @endforeach
            </select>
            <p class="field-hint" id="vat_regime_hint">Režim volíte vy — aplikace neposuzuje, zda prodej podmínky § 90 ZDPH splňuje.</p>
            @error('vat_regime')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="field" data-regime="used_goods_margin" @if (! $isMargin) hidden @endif>
            <label for="margin_vat_rate">Interní sazba DPH z přirážky *</label>
            <select id="margin_vat_rate" name="margin_vat_rate" @disabled(! $isMargin) aria-describedby="margin_vat_rate_hint">
                @foreach (UsedGoodsMargin::rateOptions() as $rate)
                    <option value="{{ $rate }}" @selected($currentMarginRate === $rate)>{{ $rate }} %</option>
                @endforeach
            </select>
            <p class="field-hint" id="margin_vat_rate_hint">Jen pro interní výpočet DPH z přirážky (použité telefony: 21 %). Na doklad se DPH nevyčísluje.</p>
            @error('margin_vat_rate')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="field span-2" data-regime="standard" @if ($isMargin) hidden @endif>
            <p class="field-hint">
                <strong>Běžný režim:</strong> cena za MJ se zadává <strong>bez DPH</strong>, DPH se dopočítá dle sazby položky.
                Při přepnutí režimu se ceny nepřepočítávají — zkontrolujte je.
            </p>
        </div>

        <div class="field span-2" data-regime="used_goods_margin" @if (! $isMargin) hidden @endif>
            <p class="field-hint">
                <strong>Zvláštní režim - použité zboží (§ 90 ZDPH):</strong> zadávejte <strong>konečnou prodejní cenu za kus včetně DPH</strong>
                (částku, kterou zákazník zaplatí — DPH se k ní už nepřičítá) a <strong>interní pořizovací cenu za kus</strong>.
                DPH se počítá interně jen z kladné přirážky (prodejní − pořizovací cena) a na doklad se nevyčísluje;
                doklad ponese text „zvláštní režim - použité zboží“. Množství se zadává v celých kusech.
                Pořizovací cena, přirážka ani DPH z přirážky se na dokladu netisknou.
                Při přepnutí režimu se ceny nepřepočítávají — zkontrolujte je.
            </p>
        </div>
    @else
        <input type="hidden" name="vat_regime" value="{{ VatRegime::Standard->value }}">
    @endif
</div>

<h2 class="section-title">Položky</h2>
@error('items')<p class="field-error">{{ $message }}</p>@enderror

<table class="table items-table" id="items-table">
    <thead>
    <tr>
        <th>Popis *</th>
        <th class="w-qty">
            <span data-regime="standard" @if ($isMargin) hidden @endif>Množství *</span>
            <span data-regime="used_goods_margin" @if (! $isMargin) hidden @endif>Množství (celé ks) *</span>
        </th>
        <th class="w-unit">MJ *</th>
        <th class="w-price">
            <span data-regime="standard" @if ($isMargin) hidden @endif>Cena/MJ{{ $vatPayer ? ' bez DPH' : '' }} *</span>
            <span data-regime="used_goods_margin" @if (! $isMargin) hidden @endif>Prodejní cena/MJ vč. DPH *</span>
        </th>
        @if ($vatPayer)
            <th class="w-vat" data-regime="standard" @if ($isMargin) hidden @endif>DPH %</th>
            <th class="w-price" data-regime="used_goods_margin" @if (! $isMargin) hidden @endif>Pořizovací cena/MJ (interní) *</th>
        @endif
        <th class="w-remove"><span class="sr-only">Akce</span></th>
    </tr>
    </thead>
    <tbody id="items-body">
    @foreach ($oldItems as $index => $item)
        <tr class="item-row">
            <td>
                <label class="sr-only" for="items-{{ $index }}-description">Popis položky {{ $index + 1 }}</label>
                <input type="text" id="items-{{ $index }}-description"
                       name="items[{{ $index }}][description]"
                       value="{{ $item['description'] ?? '' }}" required>
                @error("items.{$index}.description")<p class="field-error">{{ $message }}</p>@enderror
            </td>
            <td>
                <label class="sr-only" for="items-{{ $index }}-quantity">Množství položky {{ $index + 1 }}</label>
                <input type="text" id="items-{{ $index }}-quantity" inputmode="decimal"
                       name="items[{{ $index }}][quantity]"
                       value="{{ $item['quantity'] ?? '1' }}" required>
                @error("items.{$index}.quantity")<p class="field-error">{{ $message }}</p>@enderror
            </td>
            <td>
                <label class="sr-only" for="items-{{ $index }}-unit">Jednotka položky {{ $index + 1 }}</label>
                <input type="text" id="items-{{ $index }}-unit"
                       name="items[{{ $index }}][unit]"
                       value="{{ $item['unit'] ?? 'ks' }}" required>
                @error("items.{$index}.unit")<p class="field-error">{{ $message }}</p>@enderror
            </td>
            <td>
                <label class="sr-only" for="items-{{ $index }}-unit_price">Cena položky {{ $index + 1 }}</label>
                <input type="text" id="items-{{ $index }}-unit_price" inputmode="decimal"
                       name="items[{{ $index }}][unit_price]"
                       value="{{ $item['unit_price'] ?? '' }}" required>
                @error("items.{$index}.unit_price")<p class="field-error">{{ $message }}</p>@enderror
            </td>
            @if ($vatPayer)
                <td data-regime="standard" @if ($isMargin) hidden @endif>
                    <label class="sr-only" for="items-{{ $index }}-vat_rate">Sazba DPH položky {{ $index + 1 }}</label>
                    <select id="items-{{ $index }}-vat_rate" name="items[{{ $index }}][vat_rate]" @disabled($isMargin)>
                        @foreach (['21', '12', '0'] as $rate)
                            <option value="{{ $rate }}" @selected(($item['vat_rate'] ?? '21') === $rate)>{{ $rate }} %</option>
                        @endforeach
                    </select>
                    @error("items.{$index}.vat_rate")<p class="field-error">{{ $message }}</p>@enderror
                </td>
                <td data-regime="used_goods_margin" @if (! $isMargin) hidden @endif>
                    <label class="sr-only" for="items-{{ $index }}-acquisition_unit_price">Pořizovací cena položky {{ $index + 1 }}</label>
                    <input type="text" id="items-{{ $index }}-acquisition_unit_price" inputmode="decimal"
                           name="items[{{ $index }}][acquisition_unit_price]"
                           value="{{ $item['acquisition_unit_price'] ?? '' }}" @disabled(! $isMargin)>
                    @error("items.{$index}.acquisition_unit_price")<p class="field-error">{{ $message }}</p>@enderror
                </td>
            @endif
            <td class="w-remove">
                <button type="button" class="link-button danger remove-item" aria-label="Odebrat položku">✕</button>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

<button type="button" class="btn" id="add-item">+ Přidat položku</button>

<h2 class="section-title">Sleva na celou fakturu</h2>
<div class="form-grid">
    <div class="field">
        <label for="discount_type">Typ slevy</label>
        <select id="discount_type" name="discount_type" aria-describedby="discount_hint">
            <option value="none" @selected($discountType === 'none')>Bez slevy</option>
            <option value="percent" @selected($discountType === 'percent')>Procentní sleva</option>
            <option value="fixed" @selected($discountType === 'fixed')>Pevná částka (Kč)</option>
        </select>
        @error('discount_type')<p class="field-error">{{ $message }}</p>@enderror
    </div>
    <div class="field">
        <label for="discount_value" id="discount_value_label">{{ $discountType === 'percent' ? 'Sleva (%)' : 'Sleva (Kč)' }}</label>
        <input type="number" id="discount_value" name="discount_value" inputmode="decimal" min="0" step="0.01"
               @if ($discountType === 'percent') max="100" @endif value="{{ $discountValue }}" aria-describedby="discount_hint">
        @error('discount_value')<p class="field-error">{{ $message }}</p>@enderror
    </div>
    <p class="field-hint span-2" id="discount_hint">
        Sleva platí pro celý doklad. Procentní sleva může být nejvýše 100 %.
        Pevná částka se odečte přesně z konečné ceny včetně DPH a nesmí ji překročit.
        Rozdělení slevy mezi položky a přepočet DPH se provedou při uložení. U volby „Bez slevy“ zadejte hodnotu 0.
    </p>
</div>

<div class="form-grid mt">
    <div class="field span-2">
        <label for="note">Poznámka (tiskne se na fakturu)</label>
        <textarea id="note" name="note" rows="2">{{ old('note', $invoice?->note ?? ($defaults['note'] ?? '')) }}</textarea>
        @error('note')<p class="field-error">{{ $message }}</p>@enderror
    </div>
    <div class="field span-2">
        <label for="internal_note">Interní poznámka (netiskne se)</label>
        <textarea id="internal_note" name="internal_note" rows="2">{{ old('internal_note', $invoice?->internal_note) }}</textarea>
        @error('internal_note')<p class="field-error">{{ $message }}</p>@enderror
    </div>
</div>

<template id="item-row-template">
    <tr class="item-row">
        <td><input type="text" name="items[__I__][description]" aria-label="Popis položky" required></td>
        <td><input type="text" name="items[__I__][quantity]" value="1" inputmode="decimal" aria-label="Množství" required></td>
        <td><input type="text" name="items[__I__][unit]" value="ks" aria-label="Jednotka" required></td>
        <td><input type="text" name="items[__I__][unit_price]" inputmode="decimal" aria-label="Cena za jednotku" required></td>
        @if ($vatPayer)
            <td data-regime="standard" @if ($isMargin) hidden @endif>
                <select name="items[__I__][vat_rate]" aria-label="Sazba DPH" @disabled($isMargin)>
                    <option value="21" selected>21 %</option>
                    <option value="12">12 %</option>
                    <option value="0">0 %</option>
                </select>
            </td>
            <td data-regime="used_goods_margin" @if (! $isMargin) hidden @endif>
                <input type="text" name="items[__I__][acquisition_unit_price]" inputmode="decimal"
                       aria-label="Pořizovací cena za jednotku (interní)" @disabled(! $isMargin)>
            </td>
        @endif
        <td class="w-remove">
            <button type="button" class="link-button danger remove-item" aria-label="Odebrat položku">✕</button>
        </td>
    </tr>
</template>

<script>
    (function () {
        let index = {{ count($oldItems) }};
        const body = document.getElementById('items-body');
        const template = document.getElementById('item-row-template');
        const regimeSelect = document.getElementById('vat_regime');
        const discountType = document.getElementById('discount_type');
        const discountValue = document.getElementById('discount_value');
        function applyDiscountType() {
            const percent = discountType.value === 'percent';
            if (discountType.value === 'none') discountValue.value = '0';
            document.getElementById('discount_value_label').textContent = percent ? 'Sleva (%)' : 'Sleva (Kč)';
            if (percent) discountValue.max = '100';
            else discountValue.removeAttribute('max');
        }
        discountType.addEventListener('change', applyDiscountType);

        // Přepínání polí dle režimu DPH: neaktivní pole se skryjí a zakážou
        // (disabled → neodesílají se), takže server dostane jen pole zvoleného
        // režimu. Bez JS platí stav vykreslený serverem dle uloženého/old() režimu.
        function applyRegime() {
            const value = regimeSelect ? regimeSelect.value : 'standard';
            document.querySelectorAll('[data-regime]').forEach(function (element) {
                const active = element.dataset.regime === value;
                element.hidden = !active;
                element.querySelectorAll('input, select, textarea').forEach(function (control) {
                    control.disabled = !active;
                });
            });
        }

        if (regimeSelect) {
            regimeSelect.addEventListener('change', applyRegime);
        }

        // Způsob zadání odběratele: neaktivní část se skryje a zakáže
        // (disabled → neodesílá se), takže server nikdy nedostane vybraný
        // kontakt a ručně zadanou osobu zároveň. Bez JS platí stav vykreslený
        // serverem dle old() režimu.
        function applyRecipientMode() {
            const checked = document.querySelector('input[name="recipient_mode"]:checked');
            const value = checked ? checked.value : 'existing';
            document.querySelectorAll('[data-recipient]').forEach(function (element) {
                const active = element.dataset.recipient === value;
                element.hidden = !active;
                element.querySelectorAll('input, select').forEach(function (control) {
                    control.disabled = !active;
                });
            });
        }

        document.querySelectorAll('input[name="recipient_mode"]').forEach(function (radio) {
            radio.addEventListener('change', applyRecipientMode);
        });

        document.getElementById('add-item').addEventListener('click', function () {
            const html = template.innerHTML.replaceAll('__I__', String(index++));
            body.insertAdjacentHTML('beforeend', html);
            applyRegime();
            body.lastElementChild.querySelector('input').focus();
        });

        document.getElementById('items-table').addEventListener('click', function (event) {
            const button = event.target.closest('.remove-item');
            if (!button) return;
            const rows = body.querySelectorAll('.item-row');
            if (rows.length <= 1) {
                alert('Faktura musí mít alespoň jednu položku.');
                return;
            }
            button.closest('.item-row').remove();
        });
    })();
</script>
