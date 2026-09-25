<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Přehled') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <span class="brand-mark">MK</span>
            <span class="brand-name">Factory</span>
        </div>

        @isset($currentOrganization)
            <div class="sidebar-org" title="Aktivní organizace">
                {{ $currentOrganization->profile_name ?: $currentOrganization->name }}
            </div>
        @endisset

        <nav class="sidebar-nav" aria-label="Hlavní navigace">
            <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>Dashboard</a>
            <a href="{{ route('invoices.index') }}" @class(['active' => request()->routeIs('invoices.*')])>Vydané faktury</a>
            <a href="{{ route('received.index') }}" @class(['active' => request()->routeIs('received.*') || request()->routeIs('attachments.*')])>Přijaté faktury</a>
            <a href="{{ route('contacts.index') }}" @class(['active' => request()->routeIs('contacts.*')])>Kontakty</a>
            <a href="{{ route('projects.index') }}" @class(['active' => request()->routeIs('projects.*')])>Projekty</a>
            <a href="{{ route('settings.edit') }}" @class(['active' => request()->routeIs('settings.*') || request()->routeIs('bank-accounts.*') || request()->routeIs('number-series.*')])>Nastavení</a>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">{{ auth()->user()?->name }}</div>
            <div class="sidebar-actions">
                <a href="{{ route('organizations.select') }}">Změnit organizaci</a>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="link-button">Odhlásit se</button>
                </form>
            </div>
        </div>
    </aside>

    <main class="main">
        @if (session('status'))
            <div class="flash flash-success" role="status">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="flash flash-error" role="alert">{{ session('error') }}</div>
        @endif

        @yield('content')
    </main>
</div>
</body>
</html>
