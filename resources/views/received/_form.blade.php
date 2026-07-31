@php
    /** @var ?\App\Models\ReceivedInvoice $invoice */
    use App\Domain\Money\Money;
@endphp

<div class="form-grid">
    <div class="field span-2">
        <label for="contact_id">Dodavatel *</label>
        <select id="contact_id" name="contact_id" required>
            <option value="">— vyberte —</option>
            @foreach ($suppliers as $supplier)
                <option value="{{ $supplier->id }}" @selected((int) old('contact_id', $invoice?->contact_id) === $supplier->id)>
                    {{ $supplier->name }}{{ $supplier->ico ? ' (IČO '.$supplier->ico.')' : '' }}
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
        <label for="supplier_invoice_number">Číslo faktury dodavatele</label>
        <input type="text" id="supplier_invoice_number" name="supplier_invoice_number"
               value="{{ old('supplier_invoice_number', $invoice?->supplier_invoice_number) }}">
        @error('supplier_invoice_number')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="variable_symbol">Variabilní symbol</label>
        <input type="text" id="variable_symbol" name="variable_symbol" inputmode="numeric"
               value="{{ old('variable_symbol', $invoice?->variable_symbol) }}">
        @error('variable_symbol')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="issue_date">Datum vystavení</label>
        <input type="date" id="issue_date" name="issue_date"
               value="{{ old('issue_date', $invoice?->issue_date?->toDateString()) }}">
        @error('issue_date')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="received_date">Datum přijetí *</label>
        <input type="date" id="received_date" name="received_date"
               value="{{ old('received_date', $invoice?->received_date?->toDateString() ?? now()->toDateString()) }}" required>
        @error('received_date')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="due_date">Datum splatnosti</label>
        <input type="date" id="due_date" name="due_date"
               value="{{ old('due_date', $invoice?->due_date?->toDateString()) }}">
        @error('due_date')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="total">Částka celkem (Kč) *</label>
        <input type="text" id="total" name="total" inputmode="decimal"
               value="{{ old('total', $invoice ? Money::fromMinor((int) $invoice->total_minor)->toDecimalString() : '') }}" required>
        @error('total')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="vat">Z toho DPH (Kč)</label>
        <input type="text" id="vat" name="vat" inputmode="decimal"
               value="{{ old('vat', $invoice && $invoice->vat_minor !== null ? Money::fromMinor((int) $invoice->vat_minor)->toDecimalString() : '') }}">
        @error('vat')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field span-2">
        <label for="note">Poznámka</label>
        <textarea id="note" name="note" rows="3">{{ old('note', $invoice?->note) }}</textarea>
        @error('note')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    @unless ($invoice)
        <div class="field span-2">
            <label for="attachment">Příloha (PDF / JPG / PNG, max. 10 MB)</label>
            <input type="file" id="attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png">
            @error('attachment')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    @endunless
</div>
