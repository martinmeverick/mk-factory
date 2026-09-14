@extends('layouts.app')
@use('App\Support\Format')

@section('title', $invoice->invoice_number ?? 'Koncept faktury')

@php
    // Zvláštní režim - použité zboží: položky nenesou běžnou DPH, interní
    // evidence přirážky se zobrazuje v samostatném panelu (netiskne se).
    $isMargin = $invoice->isUsedGoodsMargin();
    $withVat = ! $isMargin && $invoice->items->contains(fn ($item) => $item->vat_rate !== null);
@endphp

@section('content')
    <header class="page-header">
        <div>
            <h1>{{ $invoice->invoice_number ? 'Faktura '.$invoice->invoice_number : 'Koncept faktury #'.$invoice->id }}</h1>
            <div class="page-subtitle">
                @include('partials.status-badge', ['status' => $invoice->display_status])
                <span class="muted">{{ $invoice->contact?->name }}</span>
            </div>
        </div>
        <div class="header-actions">
            <a href="{{ route('invoices.pdf', $invoice) }}" class="btn" target="_blank" rel="noopener">Stáhnout PDF</a>

            @if ($invoice->isEditable())
                <a href="{{ route('invoices.edit', $invoice) }}" class="btn">Upravit</a>
                <form method="post" action="{{ route('invoices.issue', $invoice) }}"
                      onsubmit="return confirm('Vystavit fakturu? Bude jí přiděleno číslo a už ji nepůjde upravovat.')">
                    @csrf
                    <button type="submit" class="btn btn-primary">Vystavit fakturu</button>
                </form>
                <form method="post" action="{{ route('invoices.destroy', $invoice) }}"
                      onsubmit="return confirm('Opravdu smazat koncept?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">Smazat</button>
                </form>
            @else
                @if (in_array($invoice->status->value, ['issued', 'partially_paid'], true))
                    <form method="post" action="{{ route('invoices.mark-paid', $invoice) }}"
                          onsubmit="return confirm('Označit fakturu jako plně uhrazenou?')">
                        @csrf
                        <button type="submit" class="btn btn-primary">Označit jako uhrazenou</button>
                    </form>
                @endif
                @if ($invoice->status->value === 'issued' && $invoice->payments->isEmpty())
                    <form method="post" action="{{ route('invoices.cancel', $invoice) }}"
                          onsubmit="return confirm('Opravdu stornovat fakturu? Číslo zůstane spotřebované.')">
                        @csrf
                        <button type="submit" class="btn btn-danger">Stornovat</button>
                    </form>
                @endif
            @endif
        </div>
    </header>

    <div class="detail-grid">
        <section class="panel">
            <h2>Údaje faktury</h2>
            <dl class="detail-list">
                <dt>Odběratel</dt>
                <dd>{{ $invoice->contact?->name }}</dd>
                <dt>Projekt</dt>
                <dd>{{ $invoice->project?->name ?? '—' }}</dd>
                <dt>Číselná řada</dt>
                <dd>{{ $invoice->numberSeries?->name ?? '—' }}</dd>
                <dt>Variabilní symbol</dt>
                <dd>{{ $invoice->variable_symbol ?? '—' }}</dd>
                <dt>Datum vystavení</dt>
                <dd>{{ Format::date($invoice->issue_date) }}</dd>
                <dt>Datum splatnosti</dt>
                <dd>{{ Format::date($invoice->due_date) }}</dd>
                @if ($invoice->tax_date)
                    <dt>DUZP</dt>
                    <dd>{{ Format::date($invoice->tax_date) }}</dd>
                @endif
                <dt>Režim DPH</dt>
                <dd>{{ $invoice->vatRegime()->label() }}</dd>
                <dt>Bankovní účet</dt>
                <dd>
                    @if ($invoice->bank_account_snapshot)
                        {{ $invoice->bank_account_snapshot['account_number'] }}/{{ $invoice->bank_account_snapshot['bank_code'] }}
                    @elseif ($invoice->bankAccount)
                        {{ $invoice->bankAccount->account_number }}/{{ $invoice->bankAccount->bank_code }}
                    @else
                        —
                    @endif
                </dd>
            </dl>

            @if ($invoice->note)
                <h3>Poznámka</h3>
                <p>{{ $invoice->note }}</p>
            @endif
            @if ($invoice->internal_note)
                <h3>Interní poznámka</h3>
                <p class="muted">{{ $invoice->internal_note }}</p>
            @endif
        </section>

        <section class="panel">
            <h2>Úhrady</h2>
            <dl class="detail-list">
                <dt>Celkem</dt>
                <dd>{{ Format::money($invoice->total_minor, $invoice->currency) }}</dd>
                <dt>Uhrazeno</dt>
                <dd>{{ Format::money($invoice->paid_amount_minor, $invoice->currency) }}</dd>
                <dt>Zbývá</dt>
                <dd><strong>{{ Format::money($invoice->total_minor - $invoice->paid_amount_minor, $invoice->currency) }}</strong></dd>
            </dl>

            @if ($invoice->payments->isNotEmpty())
                <table class="table">
                    <thead>
                    <tr>
                        <th>Datum</th>
                        <th class="num">Částka</th>
                        <th>Poznámka</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($invoice->payments as $payment)
                        <tr>
                            <td>{{ Format::date($payment->paid_on) }}</td>
                            <td class="num">{{ Format::money($payment->amount_minor, $payment->currency) }}</td>
                            <td>{{ $payment->note ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

            @if (in_array($invoice->status->value, ['issued', 'partially_paid'], true))
                <h3>Zaevidovat platbu</h3>
                <form method="post" action="{{ route('invoices.payment', $invoice) }}" class="inline-form" novalidate>
                    @csrf
                    <div class="field">
                        <label for="amount">Částka (Kč)</label>
                        <input type="text" id="amount" name="amount" inputmode="decimal"
                               value="{{ old('amount', \App\Domain\Money\Money::fromMinor($invoice->total_minor - $invoice->paid_amount_minor)->toDecimalString()) }}"
                               required>
                        @error('amount')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="paid_on">Datum úhrady</label>
                        <input type="date" id="paid_on" name="paid_on" value="{{ old('paid_on', now()->toDateString()) }}" required>
                        @error('paid_on')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="payment_note">Poznámka</label>
                        <input type="text" id="payment_note" name="note" value="{{ old('note') }}">
                        @error('note')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary">Zaevidovat</button>
                </form>
            @endif
        </section>
    </div>

    <section class="panel">
        <h2>Položky</h2>
        @if ($isMargin)
            <p class="muted">Zvláštní režim - použité zboží: cena za MJ je konečná prodejní cena včetně DPH, DPH se na dokladu nevyčísluje.</p>
        @endif
        <table class="table">
            <thead>
            <tr>
                <th>Popis</th>
                <th class="num">Množství</th>
                <th>MJ</th>
                <th class="num">{{ $isMargin ? 'Prodejní cena/MJ' : 'Cena/MJ' }}</th>
                @if ($withVat)
                    <th class="num">DPH</th>
                    <th class="num">Základ</th>
                    <th class="num">DPH Kč</th>
                @endif
                <th class="num">Celkem</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($invoice->items->sortBy('position') as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ Format::quantity($item->quantity) }}</td>
                    <td>{{ $item->unit }}</td>
                    <td class="num">{{ Format::money($item->unit_price_minor, $invoice->currency) }}</td>
                    @if ($withVat)
                        <td class="num">{{ Format::vatRate($item->vat_rate) }}</td>
                        <td class="num">{{ Format::money($item->line_subtotal_minor, $invoice->currency) }}</td>
                        <td class="num">{{ Format::money($item->line_vat_minor, $invoice->currency) }}</td>
                    @endif
                    <td class="num">{{ Format::money($item->line_total_minor, $invoice->currency) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            @if ($withVat)
                <tr>
                    <td colspan="5"></td>
                    <td class="num"><strong>{{ Format::money($invoice->subtotal_minor, $invoice->currency) }}</strong></td>
                    <td class="num"><strong>{{ Format::money($invoice->vat_total_minor, $invoice->currency) }}</strong></td>
                    <td class="num"><strong>{{ Format::money($invoice->total_minor, $invoice->currency) }}</strong></td>
                </tr>
            @else
                <tr>
                    <td colspan="3"></td>
                    <td class="num muted">Celkem</td>
                    <td class="num"><strong>{{ Format::money($invoice->total_minor, $invoice->currency) }}</strong></td>
                </tr>
            @endif
            </tfoot>
        </table>
    </section>

    @if ($isMargin)
        <section class="panel">
            <h2>Interní evidence DPH z přirážky (netiskne se na doklad)</h2>
            <p class="muted">
                Sazba DPH z přirážky {{ Format::vatRate($invoice->margin_vat_rate) }}. DPH = kladná přirážka × sazba / (100 + sazba),
                zaokrouhleno na haléře po položkách. Položky s pořizovací cenou vyšší než prodejní mají přirážku, DPH i základ 0.
                Tyto hodnoty nejsou součástí položek „Základ“ a „DPH“ běžného režimu (vat_total_minor = 0).
            </p>
            <table class="table">
                <thead>
                <tr>
                    <th>Popis</th>
                    <th class="num">Pořizovací cena/MJ</th>
                    <th class="num">Prodej celkem</th>
                    <th class="num">Pořízení celkem</th>
                    <th class="num">Přirážka</th>
                    <th class="num">DPH z přirážky</th>
                    <th class="num">Základ daně z přirážky</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($invoice->items->sortBy('position') as $item)
                    <tr>
                        <td>{{ $item->description }}</td>
                        <td class="num">
                            {{ $item->acquisition_unit_price_minor !== null ? Format::money($item->acquisition_unit_price_minor, $invoice->currency) : '— chybí —' }}
                        </td>
                        <td class="num">{{ Format::money($item->line_total_minor, $invoice->currency) }}</td>
                        <td class="num">{{ Format::money($item->line_acquisition_minor, $invoice->currency) }}</td>
                        <td class="num">{{ Format::money($item->line_margin_gross_minor, $invoice->currency) }}</td>
                        <td class="num">{{ Format::money($item->line_margin_vat_minor, $invoice->currency) }}</td>
                        <td class="num">{{ Format::money($item->line_margin_base_minor, $invoice->currency) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="2" class="num muted">Celkem</td>
                    <td class="num"><strong>{{ Format::money($invoice->total_minor, $invoice->currency) }}</strong></td>
                    <td class="num"><strong>{{ Format::money($invoice->margin_acquisition_total_minor, $invoice->currency) }}</strong></td>
                    <td class="num"><strong>{{ Format::money($invoice->margin_gross_minor, $invoice->currency) }}</strong></td>
                    <td class="num"><strong>{{ Format::money($invoice->margin_vat_minor, $invoice->currency) }}</strong></td>
                    <td class="num"><strong>{{ Format::money($invoice->margin_base_minor, $invoice->currency) }}</strong></td>
                </tr>
                </tfoot>
            </table>
        </section>
    @endif
@endsection
