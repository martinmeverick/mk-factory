@extends('layouts.app')

@section('title', 'Nová faktura')

@section('content')
    <header class="page-header">
        <h1>Nová faktura</h1>
    </header>

    <section class="panel">
        <form method="post" action="{{ route('invoices.store') }}" novalidate>
            @csrf
            @include('invoices._form')

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Uložit koncept</button>
                <a href="{{ route('invoices.index') }}" class="btn">Zpět</a>
            </div>
        </form>
    </section>
@endsection
