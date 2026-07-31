@extends('layouts.app')

@section('title', 'Nastavení organizace')

@section('content')
    <header class="page-header">
        <h1>Nastavení organizace</h1>
    </header>

    <div class="detail-grid">
        <section class="panel">
            <h2>Údaje organizace</h2>
            <form method="post" action="{{ route('settings.profile') }}" novalidate>
                @csrf
                @method('PUT')
                <div class="form-grid">
                    <div class="field span-2">
                        <label for="name">Obchodní název *</label>
                        <input type="text" id="name" name="name" value="{{ old('name', $organization->name) }}" required>
                        @error('name')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="ico">IČO</label>
                        <input type="text" id="ico" name="ico" value="{{ old('ico', $organization->ico) }}">
                        @error('ico')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="dic">DIČ</label>
                        <input type="text" id="dic" name="dic" value="{{ old('dic', $organization->dic) }}">
                        @error('dic')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field span-2">
                        <label for="street">Ulice a č. p.</label>
                        <input type="text" id="street" name="street" value="{{ old('street', $organization->street) }}">
                        @error('street')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="city">Město</label>
                        <input type="text" id="city" name="city" value="{{ old('city', $organization->city) }}">
                        @error('city')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="zip">PSČ</label>
                        <input type="text" id="zip" name="zip" value="{{ old('zip', $organization->zip) }}">
                        @error('zip')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="email">E-mail</label>
                        <input type="email" id="email" name="email" value="{{ old('email', $organization->email) }}">
                        @error('email')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="phone">Telefon</label>
                        <input type="text" id="phone" name="phone" value="{{ old('phone', $organization->phone) }}">
                        @error('phone')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="website">Web</label>
                        <input type="text" id="website" name="website" value="{{ old('website', $organization->website) }}">
                        @error('website')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="country">Země</label>
                        <input type="text" id="country" name="country" maxlength="2"
                               value="{{ old('country', $organization->country ?? 'CZ') }}">
                        @error('country')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Uložit údaje</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Fakturace</h2>
            <form method="post" action="{{ route('settings.invoicing') }}" novalidate>
                @csrf
                @method('PUT')
                <div class="form-grid">
                    <div class="field">
                        <label for="vat_payer">Plátce DPH *</label>
                        <select id="vat_payer" name="vat_payer">
                            <option value="1" @selected(old('vat_payer', $settings->vat_payer ? '1' : '0') === '1')>Ano — plátce DPH</option>
                            <option value="0" @selected(old('vat_payer', $settings->vat_payer ? '1' : '0') === '0')>Ne — neplátce DPH</option>
                        </select>
                        @error('vat_payer')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="default_due_days">Výchozí splatnost (dny) *</label>
                        <input type="number" id="default_due_days" name="default_due_days" min="0" max="365"
                               value="{{ old('default_due_days', $settings->default_due_days) }}" required>
                        @error('default_due_days')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="default_bank_account_id">Výchozí bankovní účet</label>
                        <select id="default_bank_account_id" name="default_bank_account_id">
                            <option value="">— žádný —</option>
                            @foreach ($bankAccounts as $account)
                                <option value="{{ $account->id }}"
                                        @selected((int) old('default_bank_account_id', $settings->default_bank_account_id) === $account->id)>
                                    {{ $account->name }} ({{ $account->account_number }}/{{ $account->bank_code }})
                                </option>
                            @endforeach
                        </select>
                        @error('default_bank_account_id')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="default_number_series_id">Výchozí číselná řada</label>
                        <select id="default_number_series_id" name="default_number_series_id">
                            <option value="">— žádná —</option>
                            @foreach ($numberSeries as $series)
                                <option value="{{ $series->id }}"
                                        @selected((int) old('default_number_series_id', $settings->default_number_series_id) === $series->id)>
                                    {{ $series->name }} ({{ $series->prefix }}{{ $series->year }})
                                </option>
                            @endforeach
                        </select>
                        @error('default_number_series_id')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field span-2">
                        <label for="invoice_footer_text">Text patičky faktury</label>
                        <textarea id="invoice_footer_text" name="invoice_footer_text" rows="2">{{ old('invoice_footer_text', $settings->invoice_footer_text) }}</textarea>
                        @error('invoice_footer_text')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field span-2">
                        <label for="invoice_default_note">Výchozí poznámka na faktuře</label>
                        <textarea id="invoice_default_note" name="invoice_default_note" rows="2">{{ old('invoice_default_note', $settings->invoice_default_note) }}</textarea>
                        @error('invoice_default_note')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Uložit nastavení</button>
                </div>
            </form>

            <h2 class="mt">Logo</h2>
            @if ($organization->logo_path)
                <p><img src="{{ route('settings.logo.show') }}" alt="Logo organizace" class="logo-preview"></p>
            @else
                <p class="muted">Logo zatím nebylo nahráno.</p>
            @endif
            <form method="post" action="{{ route('settings.logo') }}" enctype="multipart/form-data" class="inline-form">
                @csrf
                <div class="field">
                    <label class="sr-only" for="logo">Soubor loga</label>
                    <input type="file" id="logo" name="logo" accept=".jpg,.jpeg,.png" required>
                    @error('logo')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="btn">Nahrát logo</button>
            </form>
        </section>
    </div>

    <section class="panel">
        <h2>Bankovní účty</h2>
        @if ($bankAccounts->isNotEmpty())
            <table class="table">
                <thead>
                <tr>
                    <th>Název</th>
                    <th>Číslo účtu</th>
                    <th>IBAN</th>
                    <th>Výchozí</th>
                    <th class="actions"></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($bankAccounts as $account)
                    <tr>
                        <td>{{ $account->name }}</td>
                        <td>{{ $account->account_number }}/{{ $account->bank_code }}</td>
                        <td>{{ $account->iban }}</td>
                        <td>{{ $account->is_default ? 'Ano' : '—' }}</td>
                        <td class="actions">
                            <form method="post" action="{{ route('bank-accounts.destroy', $account) }}"
                                  onsubmit="return confirm('Smazat účet {{ $account->name }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="link-button danger">Smazat</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        <h3>Přidat účet</h3>
        <form method="post" action="{{ route('bank-accounts.store') }}" novalidate>
            @csrf
            <div class="form-grid form-grid-4">
                <div class="field">
                    <label for="ba_name">Název účtu *</label>
                    <input type="text" id="ba_name" name="name" value="{{ old('name') }}" required>
                    @error('name')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="ba_number">Číslo účtu *</label>
                    <input type="text" id="ba_number" name="account_number" value="{{ old('account_number') }}"
                           placeholder="123456789 nebo 19-123456789" required>
                    @error('account_number')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="ba_code">Kód banky *</label>
                    <input type="text" id="ba_code" name="bank_code" value="{{ old('bank_code') }}" inputmode="numeric" required>
                    @error('bank_code')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="ba_iban">IBAN (dopočítá se)</label>
                    <input type="text" id="ba_iban" name="iban" value="{{ old('iban') }}">
                    @error('iban')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="ba_bic">BIC / SWIFT</label>
                    <input type="text" id="ba_bic" name="bic" value="{{ old('bic') }}">
                    @error('bic')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="field field-checkbox">
                    <label><input type="checkbox" name="is_default" value="1" @checked(old('is_default'))> Výchozí účet</label>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Přidat účet</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Číselné řady</h2>
        @if ($numberSeries->isNotEmpty())
            <table class="table">
                <thead>
                <tr>
                    <th>Název</th>
                    <th>Prefix</th>
                    <th>Rok</th>
                    <th>Další číslo</th>
                    <th>Formát</th>
                    <th class="actions"></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($numberSeries as $series)
                    <tr>
                        <td>{{ $series->name }}</td>
                        <td>{{ $series->prefix ?: '—' }}</td>
                        <td>{{ $series->year }}</td>
                        <td>{{ $series->next_number }}</td>
                        <td><code>{{ $series->number_format }}</code></td>
                        <td class="actions">
                            <form method="post" action="{{ route('number-series.destroy', $series) }}"
                                  onsubmit="return confirm('Smazat řadu {{ $series->name }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="link-button danger">Smazat</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        <h3>Přidat řadu</h3>
        <form method="post" action="{{ route('number-series.store') }}" novalidate>
            @csrf
            <div class="form-grid form-grid-4">
                <div class="field">
                    <label for="ns_name">Název *</label>
                    <input type="text" id="ns_name" name="name" value="{{ old('name') }}" required>
                    @error('name')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="ns_prefix">Prefix</label>
                    <input type="text" id="ns_prefix" name="prefix" value="{{ old('prefix', 'FV') }}" maxlength="10">
                    @error('prefix')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="ns_year">Rok *</label>
                    <input type="number" id="ns_year" name="year" value="{{ old('year', now()->year) }}" min="2000" max="2100" required>
                    @error('year')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="ns_next">Další číslo *</label>
                    <input type="number" id="ns_next" name="next_number" value="{{ old('next_number', 1) }}" min="1" required>
                    @error('next_number')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="field span-2">
                    <label for="ns_format">Formát čísla *</label>
                    <input type="text" id="ns_format" name="number_format"
                           value="{{ old('number_format', '{PREFIX}{YEAR}{NUMBER:4}') }}" required
                           aria-describedby="ns_format_hint">
                    <p class="field-hint" id="ns_format_hint">Tokeny: {PREFIX}, {YEAR}, {YY}, {NUMBER:4}. Např. FV20260001.</p>
                    @error('number_format')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Přidat řadu</button>
            </div>
        </form>
    </section>
@endsection
