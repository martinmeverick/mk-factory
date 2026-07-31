<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktivní',
            self::Completed => 'Dokončený',
            self::Archived => 'Archivovaný',
        };
    }
}
