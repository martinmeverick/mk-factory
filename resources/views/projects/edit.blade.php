@extends('layouts.app')

@section('title', 'Úprava projektu')

@section('content')
    <header class="page-header">
        <h1>Úprava projektu</h1>
    </header>

    <section class="panel">
        <form method="post" action="{{ route('projects.update', $project) }}" novalidate>
            @csrf
            @method('PUT')
            @include('projects._form')

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Uložit změny</button>
                <a href="{{ route('projects.index') }}" class="btn">Zpět</a>
            </div>
        </form>
    </section>
@endsection
