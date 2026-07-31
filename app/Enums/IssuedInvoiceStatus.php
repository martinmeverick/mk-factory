<?php

declare(strict_types=1);

namespace App\Enums;

enum IssuedInvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Koncept',
            self::Issued => 'Vystaveno',
            self::PartiallyPaid => 'Částečně uhrazeno',
            self::Paid => 'Uhrazeno',
            self::Cancelled => 'Storno',
        };
    }
}
