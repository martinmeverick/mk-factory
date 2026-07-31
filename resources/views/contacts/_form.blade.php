@php /** @var ?\App\Models\Contact $contact */ @endphp

<div class="form-grid">
    <div class="field span-2">
        <label for="name">Název / jméno *</label>
        <input type="text" id="name" name="name" value="{{ old('name', $contact?->name) }}" required>
        @error('name')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="type">Typ *</label>
        <select id="type" name="type" required>
            @foreach (\App\Enums\ContactType::cases() as $case)
                <option value="{{ $case->value }}" @selected(old('type', $contact?->type?->value) === $case->value)>
                    {{ $case->label() }}
                </option>
            @endforeach
        </select>
        @error('type')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="ico">IČO</label>
        <input type="text" id="ico" name="ico" value="{{ old('ico', $contact?->ico) }}">
        @error('ico')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="dic">DIČ</label>
        <input type="text" id="dic" name="dic" value="{{ old('dic', $contact?->dic) }}">
        @error('dic')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field span-2">
        <label for="street">Ulice a č. p.</label>
        <input type="text" id="street" name="street" value="{{ old('street', $contact?->street) }}">
        @error('street')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="city">Město</label>
        <input type="text" id="city" name="city" value="{{ old('city', $contact?->city) }}">
        @error('city')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="zip">PSČ</label>
        <input type="text" id="zip" name="zip" value="{{ old('zip', $contact?->zip) }}">
        @error('zip')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="country">Země</label>
        <input type="text" id="country" name="country" maxlength="2"
               value="{{ old('country', $contact?->country ?? 'CZ') }}">
        @error('country')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" value="{{ old('email', $contact?->email) }}">
        @error('email')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field">
        <label for="phone">Telefon</label>
        <input type="text" id="phone" name="phone" value="{{ old('phone', $contact?->phone) }}">
        @error('phone')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field span-2">
        <label for="note">Poznámka</label>
        <textarea id="note" name="note" rows="3">{{ old('note', $contact?->note) }}</textarea>
        @error('note')<p class="field-error">{{ $message }}</p>@enderror
    </div>
</div>
