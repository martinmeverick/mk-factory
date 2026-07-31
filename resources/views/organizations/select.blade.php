<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Výběr organizace · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="auth-body">
<main class="auth-card">
    <h1>Vyberte organizaci</h1>

    @if ($organizations->isEmpty())
        <p class="muted">Nejste členem žádné organizace. Kontaktujte správce systému.</p>
    @else
        <ul class="org-list">
            @foreach ($organizations as $organization)
                <li>
                    <form method="post" action="{{ route('organizations.choose', $organization) }}">
                        @csrf
                        <button type="submit" class="org-choice">
                            <span class="org-name">{{ $organization->name }}</span>
                            @if ($organization->ico)
                                <span class="muted">IČO {{ $organization->ico }}</span>
                            @endif
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    <form method="post" action="{{ route('logout') }}" class="mt">
        @csrf
        <button type="submit" class="link-button">Odhlásit se</button>
    </form>
</main>
</body>
</html>
