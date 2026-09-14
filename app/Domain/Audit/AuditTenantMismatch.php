<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use DomainException;

/**
 * Auditovaný subjekt patří jiné organizaci než aktuální tenant context,
 * nebo organizaci nelze určit. Záznam se v takovém případě NEVYTVOŘÍ
 * (fail closed) — chybný audit je horší než žádný.
 */
final class AuditTenantMismatch extends DomainException
{
    public static function between(int $subjectOrganizationId, int $contextOrganizationId): self
    {
        return new self(sprintf(
            'Auditovaný záznam patří organizaci %d, ale aktivní je organizace %d.',
            $subjectOrganizationId,
            $contextOrganizationId,
        ));
    }

    public static function missingOrganization(string $action): self
    {
        return new self(sprintf(
            'Audit „%s“ nelze zapsat: organizaci nelze odvodit ze subjektu a nebyla předána explicitně.',
            $action,
        ));
    }
}
