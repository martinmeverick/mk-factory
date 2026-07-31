@extends('layouts.app')
@use('App\Support\Format')

@section('title', 'Přijaté faktury')

@section('content')
    <header class="page-header">
        <h1>Přijaté faktury</h1>
        <a href="{{ route('received.create') }}" class="btn btn-primary">Nová přijatá faktura</a>
    </header>

    <nav class="filter-tabs" aria-label="Filtr přijatých faktur">
        <a href="{{ route('received.index') }}" @class(['active' => ! $filter])>Vše</a>
        <a href="{{ route('received.index', ['stav' => 'received']) }}" @class(['active' => $filter === 'received'])>Přijaté</a>
        <a href="{{ route('received.index', ['stav' => 'approved']) }}" @class(['active' => $filter === 'approved'])>Schválené</a>
        <a href="{{ route('received.index', ['stav' => 'overdue']) }}" @class(['active' => $filter === 'overdue'])>Po splatnosti</a>
        <a href="{{ route('received.index', ['stav' => 'paid']) }}" @class(['active' => $filter === 'paid'])>Uhrazené</a>
        <a href="{{ route('received.index', ['stav' => 'rejected']) }}" @class(['active' => $filter === 'rejected'])>Zamítnuté</a>
    </nav>

    <section class="panel">
        @if ($invoices->isEmpty())
            <p class="muted">Žádné přijaté faktury neodpovídají filtru.</p>
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>Dodavatel</th>
                    <th>Číslo dokladu</th>
                    <th>Přijato</th>
                    <th>Splatnost</th>
                    <th>Stav</th>
                    <th class="num">Částka</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td><a href="{{ route('received.show', $invoice) }}">{{ $invoice->contact?->name }}</a></td>
                        <td>{{ $invoice->supplier_invoice_number ?? '—' }}</td>
                        <td>{{ Format::date($invoice->received_date) }}</td>
                        <td>{{ Format::date($invoice->due_date) }}</td>
                        <td>@include('partials.status-badge', ['status' => $invoice->display_status])</td>
                        <td class="num">{{ Format::money($invoice->total_minor, $invoice->currency) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            {{ $invoices->links() }}
        @endif
    </section>
@endsection
