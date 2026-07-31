@php
    /** @var ?\App\Models\IssuedInvoice $invoice */
    use App\Domain\Money\Money;

    $oldItems = old('items');
    if ($oldItems === null && $invoice) {
        $oldItems = $invoice->items->sortBy('position')->values()->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.'),
            'unit' => $item->unit,
            'unit_price' => Money::fromMinor((int) $item->unit_price_minor)->toDecimalString(),
            'vat_rate' => $item->vat_rate !== null ? (string) (int) $item->vat_rate : null,
        ])->all();
    }
    $oldItems = $oldItems ?: [['description' => '', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '', 'vat_rate' => '21']];
@endphp

<div class="form-grid">
    <div class="field span-2">
        <label for="contact_id">Odběratel *</label>
        <select id="contact_id" name="contact_id" required>
            <option value="">— vyberte —</option>
            @foreach ($customers as $customer)
                <option value="{{ $customer->id }}" @selected((int) old('contact_id', $invoice?->contact_id) === $customer->id)>
                    {{ $customer->name }}{{ $customer->ico ? ' (IČO '.$customer->ico.')' : '' }}
                </option>
            @endforeach
        </select>
        @error('contact_id')<p class="field-error">{{ $message }}</p>@enderror
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
    @endif
</div>

<h2 class="section-title">Položky</h2>
@error('items')<p class="field-error">{{ $message }}</p>@enderror

<table class="table items-table" id="items-table">
    <thead>
    <tr>
        <th>Popis *</th>
        <th class="w-qty">Množství *</th>
        <th class="w-unit">MJ *</th>
        <th class="w-price">Cena/MJ *</th>
        @if ($vatPayer)
            <th class="w-vat">DPH %</th>
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
                <td>
                    <label class="sr-only" for="items-{{ $index }}-vat_rate">Sazba DPH položky {{ $index + 1 }}</label>
                    <select id="items-{{ $index }}-vat_rate" name="items[{{ $index }}][vat_rate]">
                        @foreach (['21', '12', '0'] as $rate)
                            <option value="{{ $rate }}" @selected(($item['vat_rate'] ?? '21') === $rate)>{{ $rate }} %</option>
                        @endforeach
                    </select>
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
            <td>
                <select name="items[__I__][vat_rate]" aria-label="Sazba DPH">
                    <option value="21" selected>21 %</option>
                    <option value="12">12 %</option>
                    <option value="0">0 %</option>
                </select>
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

        document.getElementById('add-item').addEventListener('click', function () {
            const html = template.innerHTML.replaceAll('__I__', String(index++));
            body.insertAdjacentHTML('beforeend', html);
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
