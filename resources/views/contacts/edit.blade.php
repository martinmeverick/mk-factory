@extends('layouts.app')

@section('title', 'Úprava kontaktu')

@section('content')
    <header class="page-header">
        <h1>Úprava kontaktu</h1>
    </header>

    <section class="panel">
        <form method="post" action="{{ route('contacts.update', $contact) }}" novalidate>
            @csrf
            @method('PUT')
            @include('contacts._form')

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Uložit změny</button>
                <a href="{{ route('contacts.index') }}" class="btn">Zpět</a>
            </div>
        </form>
    </section>
@endsection
