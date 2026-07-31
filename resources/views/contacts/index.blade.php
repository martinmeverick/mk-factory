@extends('layouts.app')

@section('title', 'Kontakty')

@section('content')
    <header class="page-header">
        <h1>Kontakty</h1>
        <a href="{{ route('contacts.create') }}" class="btn btn-primary">Nový kontakt</a>
    </header>

    <nav class="filter-tabs" aria-label="Filtr kontaktů">
        <a href="{{ route('contacts.index') }}" @class(['active' => ! $type])>Vše</a>
        <a href="{{ route('contacts.index', ['typ' => 'customer']) }}" @class(['active' => $type === 'customer'])>Odběratelé</a>
        <a href="{{ route('contacts.index', ['typ' => 'supplier']) }}" @class(['active' => $type === 'supplier'])>Dodavatelé</a>
    </nav>

    <section class="panel">
        @if ($contacts->isEmpty())
            <p class="muted">Zatím žádné kontakty.</p>
        @else
            <table class="table">
                <thead>
                <tr>
                    <th>Název</th>
                    <th>Typ</th>
                    <th>IČO</th>
                    <th>E-mail</th>
                    <th>Telefon</th>
                    <th class="actions"></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($contacts as $contact)
                    <tr>
                        <td><a href="{{ route('contacts.edit', $contact) }}">{{ $contact->name }}</a></td>
                        <td>{{ $contact->type->label() }}</td>
                        <td>{{ $contact->ico ?? '—' }}</td>
                        <td>{{ $contact->email ?? '—' }}</td>
                        <td>{{ $contact->phone ?? '—' }}</td>
                        <td class="actions">
                            <form method="post" action="{{ route('contacts.destroy', $contact) }}"
                                  onsubmit="return confirm('Opravdu smazat kontakt {{ $contact->name }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="link-button danger">Smazat</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            {{ $contacts->links() }}
        @endif
    </section>
@endsection
