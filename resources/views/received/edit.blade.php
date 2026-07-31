@extends('layouts.app')

@section('title', 'Úprava přijaté faktury')

@section('content')
    <header class="page-header">
        <h1>Úprava přijaté faktury</h1>
    </header>

    <section class="panel">
        <form method="post" action="{{ route('received.update', $invoice) }}" novalidate>
            @csrf
            @method('PUT')
            @include('received._form')

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Uložit změny</button>
                <a href="{{ route('received.show', $invoice) }}" class="btn">Zpět</a>
            </div>
        </form>
    </section>
@endsection
