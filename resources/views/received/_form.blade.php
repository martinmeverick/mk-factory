@php
    /** @var ?\App\Models\ReceivedInvoice $invoice */
    use App\Domain\Money\Money;
@endphp

@php
    $supplierMode = old('supplier_mode', $invoice ? 'existing' : ($suppliers->isEmpty() ? 'ico' : 'existing'));
@endphp

<fieldset class="supplier-picker">
    <legend>Dodavatel *</legend>

    <div class="mode-choice">
        <label>
            <input type="radio" name="supplier_mode" value="existing"
                   @checked($supplierMode === 'existing') data-supplier-mode>
            Vybrat ze seznamu
        </label>
        <label>
            <input type="radio" name="supplier_mode" value="ico"
                   @checked($supplierMode === 'ico') data-supplier-mode>
            Nový podle IČO
        </label>
    </div>
    @error('supplier_mode')<p class="field-error">{{ $message }}</p>@enderror

    <div class="mode-panel" data-mode-panel="existing" @if ($supplierMode !== 'existing') hidden @endif>
        <div class="field">
            <label for="contact_id">Dodavatel ze seznamu</label>
            <select id="contact_id" name="contact_id">
                <option value="">— vyberte —</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" @selected((int) old('contact_id', $invoice?->contact_id) === $supplier->id)>
                        {{ $supplier->name }}{{ $supplier->ico ? ' (IČO '.$supplier->ico.')' : '' }}
                    </option>
                @endforeach
            </select>
            @error('contact_id')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="mode-panel" data-mode-panel="ico" @if ($supplierMode !== 'ico') hidden @endif>
        <div class="form-grid">
            <div class="field">
                <label for="supplier_ico">IČO dodavatele</label>
                <div class="input-with-button">
                    <input type="text" id="supplier_ico" name="supplier_ico" inputmode="numeric"
                           value="{{ old('supplier_ico') }}" aria-describedby="supplier_ico_hint">
                    <button type="button" class="btn" id="ares-lookup">Načíst z ARES</button>
                </div>
                <p class="field-hint" id="supplier_ico_hint">
                    Dodavatel se podle IČO dohledá, a pokud ho ještě nemáte, založí se automaticky.
                </p>
                @error('supplier_ico')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="supplier_name">Název dodavatele</label>
                <input type="text" id="supplier_name" name="supplier_name" value="{{ old('supplier_name') }}"
                       aria-describedby="supplier_name_hint">
                <p class="field-hint" id="supplier_name_hint">
                    Nepovinné — doplní se z ARESu. Vyplňte, když je registr nedostupný.
                </p>
                @error('supplier_name')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>
        <p class="ares-status" id="ares-status" role="status" aria-live="polite"></p>
    </div>
</fieldset>

<div class="form-grid">

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

<script>
    (function () {
        // Přepínání panelů dodavatele. Bez JS zůstanou viditelné oba —
        // formulář je i tak plně funkční, rozhoduje vybraný přepínač.
        const panels = document.querySelectorAll('[data-mode-panel]');
        const radios = document.querySelectorAll('[data-supplier-mode]');

        function sync() {
            const selected = document.querySelector('[data-supplier-mode]:checked')?.value;
            panels.forEach((panel) => {
                panel.hidden = panel.dataset.modePanel !== selected;
            });
        }

        radios.forEach((radio) => radio.addEventListener('change', sync));
        sync();

        // Předvyplnění z ARESu — pouze pohodlí, uložení proběhne stejně
        // i bez něj (dohledání dělá server při odeslání formuláře).
        const button = document.getElementById('ares-lookup');
        const icoInput = document.getElementById('supplier_ico');
        const nameInput = document.getElementById('supplier_name');
        const status = document.getElementById('ares-status');

        button.addEventListener('click', async function () {
            const ico = icoInput.value.trim();

            if (ico === '') {
                status.textContent = 'Zadejte nejdřív IČO.';
                return;
            }

            button.disabled = true;
            status.textContent = 'Načítám z registru…';

            try {
                const response = await fetch('/ares/' + encodeURIComponent(ico), {
                    headers: { 'Accept': 'application/json' },
                });
                const payload = await response.json();

                if (!response.ok) {
                    status.textContent = payload.message ?? 'Načtení se nezdařilo.';
                    return;
                }

                nameInput.value = payload.subject.name;
                icoInput.value = payload.subject.ico;
                status.textContent = payload.subject.name
                    + (payload.subject.text_address ? ' — ' + payload.subject.text_address : '')
                    + (payload.subject.vat_payer ? ' · plátce DPH' : ' · neplátce DPH');
            } catch (error) {
                status.textContent = 'Registr se nepodařilo kontaktovat. Vyplňte název ručně.';
            } finally {
                button.disabled = false;
            }
        });
    })();
</script>
