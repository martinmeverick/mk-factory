@extends('layouts.app')

@section('title', 'Nová přijatá faktura')

@section('content')
    <header class="page-header">
        <h1>Nová přijatá faktura</h1>
    </header>

    <section class="panel">
        <form method="post" action="{{ route('received.store') }}" enctype="multipart/form-data" novalidate>
            @csrf
            @include('received._form', ['invoice' => null])

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Zaevidovat fakturu</button>
                <a href="{{ route('received.index') }}" class="btn">Zpět</a>
            </div>
        </form>
    </section>
@endsection
