@extends('layouts.app')
@use('App\Support\Format')

@section('title', 'Přijatá faktura')

@section('content')
    <header class="page-header">
        <div>
            <h1>Přijatá faktura {{ $invoice->supplier_invoice_number ?? '#'.$invoice->id }}</h1>
            <div class="page-subtitle">
                @include('partials.status-badge', ['status' => $invoice->display_status])
                <span class="muted">{{ $invoice->contact?->name }}</span>
            </div>
        </div>
        <div class="header-actions">
            @if (in_array($invoice->status->value, ['received', 'approved'], true))
                <a href="{{ route('received.edit', $invoice) }}" class="btn">Upravit</a>
            @endif
            @if ($invoice->status->value === 'received')
                <form method="post" action="{{ route('received.approve', $invoice) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">Schválit</button>
                </form>
            @endif
            @if (in_array($invoice->status->value, ['received', 'approved'], true))
                <form method="post" action="{{ route('received.mark-paid', $invoice) }}"
                      onsubmit="return confirm('Označit fakturu jako uhrazenou?')">
                    @csrf
                    <button type="submit" class="btn btn-primary">Označit jako uhrazenou</button>
                </form>
                <form method="post" action="{{ route('received.reject', $invoice) }}"
                      onsubmit="return confirm('Opravdu zamítnout tuto fakturu?')">
                    @csrf
                    <button type="submit" class="btn btn-danger">Zamítnout</button>
                </form>
            @endif
        </div>
    </header>

    <div class="detail-grid">
        <section class="panel">
            <h2>Údaje faktury</h2>
            <dl class="detail-list">
                <dt>Dodavatel</dt>
                <dd>{{ $invoice->contact?->name }}</dd>
                <dt>Projekt</dt>
                <dd>{{ $invoice->project?->name ?? '—' }}</dd>
                <dt>Číslo dokladu</dt>
                <dd>{{ $invoice->supplier_invoice_number ?? '—' }}</dd>
                <dt>Variabilní symbol</dt>
                <dd>{{ $invoice->variable_symbol ?? '—' }}</dd>
                <dt>Datum vystavení</dt>
                <dd>{{ Format::date($invoice->issue_date) }}</dd>
                <dt>Datum přijetí</dt>
                <dd>{{ Format::date($invoice->received_date) }}</dd>
                <dt>Datum splatnosti</dt>
                <dd>{{ Format::date($invoice->due_date) }}</dd>
                <dt>Uhrazeno</dt>
                <dd>{{ $invoice->paid_at ? Format::date($invoice->paid_at) : '—' }}</dd>
            </dl>

            @if ($invoice->note)
                <h3>Poznámka</h3>
                <p>{{ $invoice->note }}</p>
            @endif
        </section>

        <section class="panel">
            <h2>Částka</h2>
            <dl class="detail-list">
                <dt>Celkem</dt>
                <dd><strong>{{ Format::money($invoice->total_minor, $invoice->currency) }}</strong></dd>
                <dt>Z toho DPH</dt>
                <dd>{{ $invoice->vat_minor !== null ? Format::money($invoice->vat_minor, $invoice->currency) : '—' }}</dd>
            </dl>

            <h2>Přílohy</h2>
            @if ($invoice->attachments->isEmpty())
                <p class="muted">Žádné přílohy.</p>
            @else
                <ul class="attachment-list">
                    @foreach ($invoice->attachments as $attachment)
                        <li>
                            <a href="{{ route('attachments.download', $attachment) }}">{{ $attachment->original_filename }}</a>
                            <span class="muted">({{ number_format($attachment->size_bytes / 1024, 0, ',', ' ') }} kB)</span>
                            <form method="post" action="{{ route('attachments.destroy', $attachment) }}"
                                  onsubmit="return confirm('Smazat přílohu?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="link-button danger">Smazat</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            <h3>Nahrát přílohu</h3>
            <form method="post" action="{{ route('attachments.store', $invoice) }}"
                  enctype="multipart/form-data" class="inline-form">
                @csrf
                <div class="field">
                    <label class="sr-only" for="attachment">Příloha</label>
                    <input type="file" id="attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png" required>
                    @error('attachment')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="btn">Nahrát</button>
            </form>
        </section>
    </div>
@endsection
