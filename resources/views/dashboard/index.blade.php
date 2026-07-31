@extends('layouts.app')
@use('App\Support\Format')

@section('title', 'Dashboard')

@section('content')
    <header class="page-header">
        <h1>Dashboard</h1>
    </header>

    <div class="stat-grid">
        <div class="stat-card stat-danger">
            <div class="stat-label">Vydané po splatnosti</div>
            <div class="stat-value">{{ $overdueIssued->count() }}</div>
            <div class="stat-detail">{{ Format::money($overdueIssuedSum) }} nedoplaceno</div>
        </div>
        <div class="stat-card stat-danger">
            <div class="stat-label">Přijaté po splatnosti</div>
            <div class="stat-value">{{ $overdueReceived->count() }}</div>
            <div class="stat-detail">{{ Format::money($overdueReceivedSum) }}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Nezaplacené vydané</div>
            <div class="stat-value">{{ $unpaidIssuedCount }}</div>
            <div class="stat-detail">{{ Format::money($unpaidIssuedSum) }} zbývá uhradit</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Vydáno tento měsíc</div>
            <div class="stat-value stat-value-sm">{{ Format::money($issuedThisMonthSum) }}</div>
            <div class="stat-detail">bez konceptů a storen</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Přijato tento měsíc</div>
            <div class="stat-value stat-value-sm">{{ Format::money($receivedThisMonthSum) }}</div>
            <div class="stat-detail">dle data přijetí</div>
        </div>
    </div>

    <section class="panel">
        <h2>Vydané faktury po splatnosti</h2>
        @if ($overdueIssued->isEmpty())
            <p class="muted">Žádné vydané faktury nejsou po splatnosti.</p>
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>Číslo</th>
                    <th>Odběratel</th>
                    <th>Splatnost</th>
                    <th class="num">Zbývá uhradit</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($overdueIssued as $invoice)
                    <tr>
                        <td><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->invoice_number }}</a></td>
                        <td>{{ $invoice->contact?->name }}</td>
                        <td>{{ Format::date($invoice->due_date) }}</td>
                        <td class="num">{{ Format::money($invoice->total_minor - $invoice->paid_amount_minor) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="panel">
        <h2>Přijaté faktury po splatnosti</h2>
        @if ($overdueReceived->isEmpty())
            <p class="muted">Žádné přijaté faktury nejsou po splatnosti.</p>
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>Dodavatel</th>
                    <th>Číslo dokladu</th>
                    <th>Splatnost</th>
                    <th class="num">Částka</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($overdueReceived as $invoice)
                    <tr>
                        <td><a href="{{ route('received.show', $invoice) }}">{{ $invoice->contact?->name }}</a></td>
                        <td>{{ $invoice->supplier_invoice_number ?? '—' }}</td>
                        <td>{{ Format::date($invoice->due_date) }}</td>
                        <td class="num">{{ Format::money($invoice->total_minor) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
