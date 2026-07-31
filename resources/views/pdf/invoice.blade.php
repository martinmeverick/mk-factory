<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="utf-8">
<title>{{ $data->isDraft() ? 'Koncept faktury' : 'Faktura '.$data->invoiceNumber }}</title>
<style>
    @page {
        margin: 14mm 14mm 24mm 14mm;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 9pt;
        line-height: 1.45;
        color: #1a1a1a;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    td, th {
        vertical-align: top;
    }

    .muted {
        color: #666666;
    }

    .label {
        font-size: 7pt;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: #666666;
    }

    .num {
        text-align: right;
        white-space: nowrap;
    }

    /* Hlavička */
    .header {
        margin-bottom: 8mm;
    }

    .header td {
        vertical-align: middle;
    }

    .header .logo img {
        max-height: 18mm;
        max-width: 60mm;
    }

    .header .title {
        text-align: right;
    }

    .header .title h1 {
        font-size: 17pt;
        font-weight: bold;
        letter-spacing: 0.5px;
    }

    .header .title .subtitle {
        font-size: 8pt;
        letter-spacing: 2px;
        text-transform: uppercase;
        color: #666666;
        margin-top: 1mm;
    }

    .rule {
        border-bottom: 2px solid #1a1a1a;
        margin-bottom: 6mm;
    }

    /* Strany */
    .parties {
        margin-bottom: 6mm;
    }

    .parties td.party {
        width: 50%;
        padding-right: 8mm;
    }

    .parties td.party + td.party {
        padding-right: 0;
        padding-left: 8mm;
    }

    .party .label {
        border-bottom: 1px solid #cccccc;
        padding-bottom: 1mm;
        margin-bottom: 2mm;
        display: block;
    }

    .party .name {
        font-size: 10.5pt;
        font-weight: bold;
        margin-bottom: 1mm;
    }

    .party .ids {
        margin-top: 2mm;
    }

    /* Platební blok */
    .payment {
        background-color: #f4f4f4;
        margin-bottom: 7mm;
    }

    .payment > tr > td,
    .payment td.pay-info,
    .payment td.pay-qr {
        padding: 4mm;
    }

    .payment .pay-table td {
        padding: 0.6mm 0;
    }

    .payment .pay-table td.key {
        width: 38%;
        color: #666666;
        padding-right: 4mm;
    }

    .payment .pay-table td.val {
        font-weight: bold;
    }

    .payment .pay-qr {
        width: 34mm;
        text-align: center;
        vertical-align: top;
    }

    .payment .pay-qr img {
        width: 27mm;
        height: 27mm;
    }

    .payment .pay-qr .qr-caption {
        font-size: 7pt;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: #666666;
        margin-top: 1mm;
    }

    /* Položky */
    .items {
        margin-bottom: 5mm;
    }

    .items th {
        font-size: 7.5pt;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #666666;
        border-bottom: 1.5px solid #1a1a1a;
        padding: 1.5mm 1.5mm;
        text-align: left;
    }

    .items th.num {
        text-align: right;
    }

    .items td {
        border-bottom: 1px solid #dddddd;
        padding: 1.8mm 1.5mm;
    }

    /* Souhrn */
    .summary td.spacer {
        width: 52%;
    }

    .summary .totals-table td {
        padding: 1.2mm 1.5mm;
    }

    .summary .totals-table td.key {
        color: #666666;
    }

    .recap th {
        font-size: 7.5pt;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #666666;
        border-bottom: 1px solid #cccccc;
        padding: 1.2mm 1.5mm;
        text-align: right;
    }

    .recap th.first {
        text-align: left;
    }

    .recap td {
        padding: 1.2mm 1.5mm;
        border-bottom: 1px solid #eeeeee;
    }

    .grand-total {
        margin-top: 2mm;
        background-color: #1a1a1a;
        color: #ffffff;
    }

    .grand-total td {
        padding: 2.5mm 3mm;
        font-size: 11pt;
        font-weight: bold;
    }

    .vat-note {
        margin-top: 2mm;
        font-style: italic;
    }

    .note-block {
        margin-top: 7mm;
    }

    .note-block .label {
        display: block;
        margin-bottom: 1mm;
    }

    /* Patička — fixovaná na spodním okraji každé stránky (dompdf) */
    .footer {
        position: fixed;
        bottom: 0;
        left: 0;
        width: 100%;
        border-top: 1px solid #cccccc;
        padding-top: 2mm;
        font-size: 7.5pt;
        color: #666666;
        text-align: center;
    }
</style>
</head>
<body>

@if ($data->footerText)
    <div class="footer">{{ $data->footerText }}</div>
@endif

{{-- Hlavička --}}
<table class="header">
    <tr>
        <td class="logo">
            @if ($data->logoDataUri)
                <img src="{{ $data->logoDataUri }}" alt="Logo">
            @endif
        </td>
        <td class="title">
            @if ($data->isDraft())
                <h1>KONCEPT</h1>
                <div class="subtitle">Nedaňový doklad</div>
            @else
                <h1>FAKTURA č. {{ $data->invoiceNumber }}</h1>
                @if ($data->vatPayer)
                    <div class="subtitle">Daňový doklad</div>
                @endif
            @endif
        </td>
    </tr>
</table>
<div class="rule"></div>

{{-- Dodavatel / odběratel --}}
<table class="parties">
    <tr>
        <td class="party">
            <span class="label">Dodavatel</span>
            <div class="name">{{ $data->supplier['name'] }}</div>
            @if ($data->supplier['street'])<div>{{ $data->supplier['street'] }}</div>@endif
            <div>
                @if ($data->supplier['zip']){{ $data->supplier['zip'] }} @endif{{ $data->supplier['city'] }}
            </div>
            @if (($data->supplier['country'] ?? null) && $data->supplier['country'] !== 'CZ')
                <div>{{ $data->supplier['country'] }}</div>
            @endif
            <div class="ids">
                @if ($data->supplier['ico'])<div>IČO: {{ $data->supplier['ico'] }}</div>@endif
                @if ($data->supplier['dic'])<div>DIČ: {{ $data->supplier['dic'] }}</div>@endif
            </div>
            <div class="ids muted">
                @if ($data->supplier['email'])<div>{{ $data->supplier['email'] }}</div>@endif
                @if ($data->supplier['phone'])<div>{{ $data->supplier['phone'] }}</div>@endif
                @if ($data->supplier['website'])<div>{{ $data->supplier['website'] }}</div>@endif
            </div>
        </td>
        <td class="party">
            <span class="label">Odběratel</span>
            <div class="name">{{ $data->customer['name'] }}</div>
            @if ($data->customer['street'])<div>{{ $data->customer['street'] }}</div>@endif
            <div>
                @if ($data->customer['zip']){{ $data->customer['zip'] }} @endif{{ $data->customer['city'] }}
            </div>
            @if (($data->customer['country'] ?? null) && $data->customer['country'] !== 'CZ')
                <div>{{ $data->customer['country'] }}</div>
            @endif
            <div class="ids">
                @if ($data->customer['ico'])<div>IČO: {{ $data->customer['ico'] }}</div>@endif
                @if ($data->customer['dic'])<div>DIČ: {{ $data->customer['dic'] }}</div>@endif
            </div>
        </td>
    </tr>
</table>

{{-- Platební údaje --}}
<table class="payment">
    <tr>
        <td class="pay-info">
            <table class="pay-table">
                @if ($data->bankAccount && ($data->bankAccount['account_number'] ?? null))
                    <tr>
                        <td class="key">Bankovní účet</td>
                        <td class="val">{{ $data->bankAccount['account_number'] }}/{{ $data->bankAccount['bank_code'] }}</td>
                    </tr>
                @endif
                @if ($data->bankAccount && ($data->bankAccount['iban'] ?? null))
                    <tr>
                        <td class="key">IBAN</td>
                        <td class="val">{{ $data->bankAccount['iban'] }}</td>
                    </tr>
                @endif
                @if ($data->bankAccount && ($data->bankAccount['bic'] ?? null))
                    <tr>
                        <td class="key">BIC / SWIFT</td>
                        <td class="val">{{ $data->bankAccount['bic'] }}</td>
                    </tr>
                @endif
                @if ($data->variableSymbol)
                    <tr>
                        <td class="key">Variabilní symbol</td>
                        <td class="val">{{ $data->variableSymbol }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="key">Datum vystavení</td>
                    <td class="val">{{ $data->issueDate->format('d.m.Y') }}</td>
                </tr>
                <tr>
                    <td class="key">Datum splatnosti</td>
                    <td class="val">{{ $data->dueDate->format('d.m.Y') }}</td>
                </tr>
                @if ($data->taxDate)
                    <tr>
                        <td class="key">DUZP</td>
                        <td class="val">{{ $data->taxDate->format('d.m.Y') }}</td>
                    </tr>
                @endif
            </table>
        </td>
        @if ($data->qrDataUri)
            <td class="pay-qr">
                <img src="{{ $data->qrDataUri }}" alt="QR Platba">
                <div class="qr-caption">QR Platba</div>
            </td>
        @endif
    </tr>
</table>

{{-- Položky --}}
<table class="items">
    <thead>
        @if ($data->vatPayer)
            <tr>
                <th style="width: 34%;">Popis</th>
                <th class="num" style="width: 8%;">Množství</th>
                <th style="width: 6%;">MJ</th>
                <th class="num" style="width: 12%;">Cena/MJ</th>
                <th class="num" style="width: 7%;">DPH&nbsp;%</th>
                <th class="num" style="width: 11%;">Základ</th>
                <th class="num" style="width: 10%;">DPH</th>
                <th class="num" style="width: 12%;">Celkem</th>
            </tr>
        @else
            <tr>
                <th style="width: 50%;">Popis</th>
                <th class="num" style="width: 11%;">Množství</th>
                <th style="width: 9%;">MJ</th>
                <th class="num" style="width: 15%;">Cena/MJ</th>
                <th class="num" style="width: 15%;">Celkem</th>
            </tr>
        @endif
    </thead>
    <tbody>
        @foreach ($data->items as $line)
            @if ($data->vatPayer)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="num">{{ $line->quantityFormatted() }}</td>
                    <td>{{ $line->unit }}</td>
                    <td class="num">{{ $line->unitPrice->formatCzech() }}</td>
                    <td class="num">{{ $line->vatRateFormatted() }}</td>
                    <td class="num">{{ $line->lineSubtotal->formatCzech() }}</td>
                    <td class="num">{{ $line->lineVat->formatCzech() }}</td>
                    <td class="num">{{ $line->lineTotal->formatCzech() }}</td>
                </tr>
            @else
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="num">{{ $line->quantityFormatted() }}</td>
                    <td>{{ $line->unit }}</td>
                    <td class="num">{{ $line->unitPrice->formatCzech() }}</td>
                    <td class="num">{{ $line->lineTotal->formatCzech() }}</td>
                </tr>
            @endif
        @endforeach
    </tbody>
</table>

{{-- Souhrn --}}
<table class="summary">
    <tr>
        <td class="spacer">
            @unless ($data->vatPayer)
                <div class="vat-note">Dodavatel není plátcem DPH.</div>
            @endunless
        </td>
        <td>
            @if ($data->vatPayer)
                <table class="recap">
                    <thead>
                        <tr>
                            <th class="first">Rekapitulace DPH</th>
                            <th>Základ</th>
                            <th>DPH</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data->vatBreakdown as $band)
                            <tr>
                                <td>Sazba {{ rtrim(rtrim(str_replace(',', '.', $band['rate']), '0'), '.') ?: '0' }}&nbsp;%</td>
                                <td class="num">{{ $band['base']->formatCzech() }}</td>
                                <td class="num">{{ $band['vat']->formatCzech() }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td>Celkem</td>
                            <td class="num">{{ $data->subtotal->formatCzech() }}</td>
                            <td class="num">{{ $data->total->minus($data->subtotal)->formatCzech() }}</td>
                        </tr>
                    </tbody>
                </table>
            @endif
            <table class="grand-total">
                <tr>
                    <td>Celkem k úhradě</td>
                    <td class="num">{{ $data->total->formatCzech() }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{-- Poznámka --}}
@if ($data->note)
    <div class="note-block">
        <span class="label">Poznámka</span>
        <div>{!! nl2br(e($data->note)) !!}</div>
    </div>
@endif

</body>
</html>
