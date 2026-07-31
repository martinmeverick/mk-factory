<?php

declare(strict_types=1);

namespace App\Enums;

enum MemberRole: string
{
    case Owner = 'owner';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Vlastník',
            self::Member => 'Člen',
        };
    }
}
