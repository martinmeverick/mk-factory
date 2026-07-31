<?php

declare(strict_types=1);

namespace App\Enums;

enum ReceivedInvoiceStatus: string
{
    case Received = 'received';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Přijato',
            self::Approved => 'Schváleno',
            self::Paid => 'Uhrazeno',
            self::Rejected => 'Zamítnuto',
        };
    }
}
