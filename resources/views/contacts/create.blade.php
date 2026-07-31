@extends('layouts.app')

@section('title', 'Nový kontakt')

@section('content')
    <header class="page-header">
        <h1>Nový kontakt</h1>
    </header>

    <section class="panel">
        <form method="post" action="{{ route('contacts.store') }}" novalidate>
            @csrf
            @include('contacts._form', ['contact' => null])

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Uložit kontakt</button>
                <a href="{{ route('contacts.index') }}" class="btn">Zpět</a>
            </div>
        </form>
    </section>
@endsection
