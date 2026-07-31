@extends('layouts.app')

@section('title', 'Projekty')

@section('content')
    <header class="page-header">
        <h1>Projekty</h1>
        <a href="{{ route('projects.create') }}" class="btn btn-primary">Nový projekt</a>
    </header>

    <section class="panel">
        @if ($projects->isEmpty())
            <p class="muted">Zatím žádné projekty.</p>
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>Název</th>
                    <th>Kód</th>
                    <th>Klient</th>
                    <th>Stav</th>
                    <th>Externí ID</th>
                    <th class="num">Faktur</th>
                    <th class="actions"></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($projects as $project)
                    <tr>
                        <td><a href="{{ route('projects.edit', $project) }}">{{ $project->name }}</a></td>
                        <td>{{ $project->code ?? '—' }}</td>
                        <td>{{ $project->contact?->name ?? '—' }}</td>
                        <td>{{ $project->status->label() }}</td>
                        <td>{{ $project->external_id ?? '—' }}</td>
                        <td class="num">{{ $project->issued_invoices_count + $project->received_invoices_count }}</td>
                        <td class="actions">
                            <form method="post" action="{{ route('projects.destroy', $project) }}"
                                  onsubmit="return confirm('Opravdu smazat projekt {{ $project->name }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="link-button danger">Smazat</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            {{ $projects->links() }}
        @endif
    </section>
@endsection
