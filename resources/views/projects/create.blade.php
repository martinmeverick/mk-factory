@extends('layouts.app')

@section('title', 'Nový projekt')

@section('content')
    <header class="page-header">
        <h1>Nový projekt</h1>
    </header>

    <section class="panel">
        <form method="post" action="{{ route('projects.store') }}" novalidate>
            @csrf
            @include('projects._form', ['project' => null])

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Uložit projekt</button>
                <a href="{{ route('projects.index') }}" class="btn">Zpět</a>
            </div>
        </form>
    </section>
@endsection
