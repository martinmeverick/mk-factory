<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Přihlášení · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="auth-body">
<main class="auth-card">
    <div class="auth-brand">
        <span class="brand-mark">MK</span>
        <span class="brand-name">Factory</span>
    </div>
    <h1>Přihlášení</h1>

    <form method="post" action="{{ route('login.attempt') }}" novalidate>
        @csrf

        <div class="field">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}"
                   required autofocus autocomplete="username">
            @error('email')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label for="password">Heslo</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
            @error('password')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="field field-checkbox">
            <label>
                <input type="checkbox" name="remember" value="1"> Zůstat přihlášen
            </label>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Přihlásit se</button>
    </form>
</main>
</body>
</html>
