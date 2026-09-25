<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nový fakturační profil · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="auth-body">
<main class="auth-card">
    <h1>Nový fakturační profil</h1>
    <p class="muted">Každý profil má vlastní faktury, kontakty, účet a číselnou řadu. Značky stejné firmy mohou mít stejné IČO; jako vystavovatele uveďte skutečný právní název.</p>
    <form method="post" action="{{ route('organizations.store') }}">
        @csrf
        @foreach (['profile_name' => 'Název profilu', 'name' => 'Právní název vystavovatele', 'ico' => 'IČO', 'dic' => 'DIČ', 'prefix' => 'Prefix číselné řady'] as $field => $label)
            <div class="field">
                <label for="{{ $field }}">{{ $label }}{{ in_array($field, ['profile_name', 'name', 'prefix']) ? ' *' : '' }}</label>
                <input id="{{ $field }}" name="{{ $field }}" type="text" value="{{ old($field) }}"
                    maxlength="{{ $field === 'prefix' ? 10 : (in_array($field, ['ico', 'dic']) ? 20 : 255) }}"
                    @required(in_array($field, ['profile_name', 'name', 'prefix']))>
                @error($field)<p class="field-error">{{ $message }}</p>@enderror
            </div>
        @endforeach
        <p class="muted">Prefix rozlišuje faktury profilů stejné firmy, například UJ nebo MK. Použijte písmena, číslice nebo pomlčku.</p>
        <div class="field">
            <label for="vat_payer">Režim vystavovatele *</label>
            <select id="vat_payer" name="vat_payer" required>
                <option value="">Vyberte režim</option>
                <option value="0" @selected(old('vat_payer') === '0')>Neplátce DPH</option>
                <option value="1" @selected(old('vat_payer') === '1')>Plátce DPH</option>
            </select>
            @error('vat_payer')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Vytvořit profil</button></div>
    </form>
    <p><a href="{{ route('organizations.select') }}">Zpět na výběr profilu</a></p>
</main>
</body>
</html>
