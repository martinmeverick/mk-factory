@extends('layouts.app')
@use('App\Support\Format')

@section('title', 'Vydané faktury')

@section('content')
    <header class="page-header">
        <h1>Vydané faktury</h1>
        <a href="{{ route('invoices.create') }}" class="btn btn-primary">Nová faktura</a>
    </header>

    <nav class="filter-tabs" aria-label="Filtr faktur">
        <a href="{{ route('invoices.index') }}" @class(['active' => ! $filter])>Vše</a>
        <a href="{{ route('invoices.index', ['stav' => 'draft']) }}" @class(['active' => $filter === 'draft'])>Koncepty</a>
        <a href="{{ route('invoices.index', ['stav' => 'issued']) }}" @class(['active' => $filter === 'issued'])>Vystavené</a>
        <a href="{{ route('invoices.index', ['stav' => 'overdue']) }}" @class(['active' => $filter === 'overdue'])>Po splatnosti</a>
        <a href="{{ route('invoices.index', ['stav' => 'paid']) }}" @class(['active' => $filter === 'paid'])>Uhrazené</a>
        <a href="{{ route('invoices.index', ['stav' => 'cancelled']) }}" @class(['active' => $filter === 'cancelled'])>Storna</a>
    </nav>

    <section class="panel">
        @if ($invoices->isEmpty())
            <p class="muted">Žádné faktury neodpovídají filtru.</p>
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>Číslo</th>
                    <th>Odběratel</th>
                    <th>Vystaveno</th>
                    <th>Splatnost</th>
                    <th>Stav</th>
                    <th class="num">Celkem</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td>
                            <a href="{{ route('invoices.show', $invoice) }}">
                                {{ $invoice->invoice_number ?? 'Koncept #'.$invoice->id }}
                            </a>
                        </td>
                        <td>{{ $invoice->contact?->name }}</td>
                        <td>{{ Format::date($invoice->issue_date) }}</td>
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
