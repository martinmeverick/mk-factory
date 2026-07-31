@php
    /** @var string $status Odvozený display_status faktury. */
    $labels = [
        'draft' => 'Koncept',
        'issued' => 'Vystaveno',
        'partially_paid' => 'Částečně uhrazeno',
        'paid' => 'Uhrazeno',
        'cancelled' => 'Storno',
        'overdue' => 'Po splatnosti',
        'received' => 'Přijato',
        'approved' => 'Schváleno',
        'rejected' => 'Zamítnuto',
    ];
@endphp
<span class="badge badge-{{ $status }}">{{ $labels[$status] ?? $status }}</span>
