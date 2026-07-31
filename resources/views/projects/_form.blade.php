@php /** @var ?\App\Models\Project $project */ @endphp

<div class="form-grid">
    <div class="field span-2">
        <label for="name">Název *</label>
        <input type="text" id="name" name="name" value="{{ old('name', $project?->name) }}" required>
        @error('name')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="code">Interní kód</label>
        <input type="text" id="code" name="code" value="{{ old('code', $project?->code) }}">
        @error('code')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="status">Stav *</label>
        <select id="status" name="status" required>
            @foreach (\App\Enums\ProjectStatus::cases() as $case)
                <option value="{{ $case->value }}" @selected(old('status', $project?->status?->value ?? 'active') === $case->value)>
                    {{ $case->label() }}
                </option>
            @endforeach
        </select>
        @error('status')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="contact_id">Klient</label>
        <select id="contact_id" name="contact_id">
            <option value="">— žádný —</option>
            @foreach ($clients as $client)
                <option value="{{ $client->id }}" @selected((int) old('contact_id', $project?->contact_id) === $client->id)>
                    {{ $client->name }}
                </option>
            @endforeach
        </select>
        @error('contact_id')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="external_id">Externí identifikátor</label>
        <input type="text" id="external_id" name="external_id"
               value="{{ old('external_id', $project?->external_id) }}"
               aria-describedby="external_id_hint">
        <p class="field-hint" id="external_id_hint">Pro napojení na externí systémy (U Jabka, MEX, Cashflow…).</p>
        @error('external_id')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field span-2">
        <label for="note">Poznámka</label>
        <textarea id="note" name="note" rows="3">{{ old('note', $project?->note) }}</textarea>
        @error('note')<p class="field-error">{{ $message }}</p>@enderror
    </div>
</div>
