<?php

declare(strict_types=1);

namespace App\Enums;

enum ContactType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Odběratel',
            self::Supplier => 'Dodavatel',
            self::Both => 'Odběratel i dodavatel',
        };
    }
}
