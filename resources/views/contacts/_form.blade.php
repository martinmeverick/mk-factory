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
        <div class="input-with-button">
            <input type="text" id="ico" name="ico" inputmode="numeric"
                   value="{{ old('ico', $contact?->ico) }}" aria-describedby="ico_hint">
            <button type="button" class="btn" id="ares-lookup">Načíst z ARES</button>
        </div>
        <p class="field-hint" id="ico_hint">U firem doplní název a adresu z registru. Fyzické osoby IČO mít nemusí.</p>
        @error('ico')<p class="field-error">{{ $message }}</p>@enderror
        <p class="ares-status" id="ares-status" role="status" aria-live="polite"></p>
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
        <label for="external_id">Externí identifikátor</label>
        <input type="text" id="external_id" name="external_id"
               value="{{ old('external_id', $contact?->external_id) }}"
               aria-describedby="contact_external_id_hint">
        <p class="field-hint" id="contact_external_id_hint">
            Klíč zákazníka v napojeném systému (U Jabka, MEX, Cashflow). Používá se k párování místo názvu.
        </p>
        @error('external_id')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="field span-2">
        <label for="note">Poznámka</label>
        <textarea id="note" name="note" rows="3">{{ old('note', $contact?->note) }}</textarea>
        @error('note')<p class="field-error">{{ $message }}</p>@enderror
    </div>
</div>

<script>
    (function () {
        const button = document.getElementById('ares-lookup');
        const status = document.getElementById('ares-status');
        const field = (id) => document.getElementById(id);

        button.addEventListener('click', async function () {
            const ico = field('ico').value.trim();

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

                const subject = payload.subject;
                field('name').value = subject.name;
                field('ico').value = subject.ico;
                if (subject.dic) field('dic').value = subject.dic;
                if (subject.street) field('street').value = subject.street;
                if (subject.city) field('city').value = subject.city;
                if (subject.zip) field('zip').value = subject.zip;
                if (subject.country) field('country').value = subject.country;

                status.textContent = 'Načteno z ARESu · '
                    + (subject.vat_payer ? 'plátce DPH' : 'neplátce DPH');
            } catch (error) {
                status.textContent = 'Registr se nepodařilo kontaktovat. Vyplňte údaje ručně.';
            } finally {
                button.disabled = false;
            }
        });
    })();
</script>
