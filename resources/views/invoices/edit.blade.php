@extends('layouts.app')

@section('title', 'Úprava konceptu')

@section('content')
    <header class="page-header">
        <h1>Úprava konceptu faktury</h1>
    </header>

    <section class="panel">
        <form method="post" action="{{ route('invoices.update', $invoice) }}" novalidate>
            @csrf
            @method('PUT')
            @include('invoices._form')

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Uložit změny</button>
                <a href="{{ route('invoices.show', $invoice) }}" class="btn">Zpět</a>
            </div>
        </form>
    </section>
@endsection
