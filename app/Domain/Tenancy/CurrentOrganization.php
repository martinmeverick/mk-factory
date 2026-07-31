<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Organization;
use RuntimeException;

/**
 * Container singleton držící aktivní organizaci požadavku.
 * Plní ho middleware (web) nebo explicitně seeder/test.
 */
final class CurrentOrganization
{
    private ?Organization $organization = null;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        $key = $this->organization?->getKey();

        return $key === null ? null : (int) $key;
    }

    public function getOrFail(): Organization
    {
        return $this->organization
            ?? throw new RuntimeException('Není nastavena aktivní organizace.');
    }
}
