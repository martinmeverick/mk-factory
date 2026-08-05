<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Zápis auditní stopy.
 *
 * Organizace se odvozuje PRIMÁRNĚ z auditovaného subjektu, ne z ambientního
 * tenant contextu — jinak by audit v konzoli, frontě nebo testu bez contextu
 * skončil s organization_id = NULL a auditní stopa by se ztratila.
 * Je-li context nastaven, musí se se subjektem shodovat; při neshodě se
 * záznam nevytvoří (fail closed).
 */
final class AuditLogger
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
    ) {
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  int|null  $organizationId  povinné, pokud subjekt organizaci nenese
     *
     * @throws AuditTenantMismatch
     */
    public function log(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        ?int $organizationId = null,
    ): void {
        $resolved = $this->resolveOrganizationId($action, $subject, $organizationId);

        $log = new AuditLog([
            'organization_id' => $resolved,
            'user_id' => Auth::id(),
            'action' => $action,
            'changes' => $changes === [] ? null : $changes,
        ]);

        if ($subject !== null) {
            $log->subject()->associate($subject);
        }

        $log->save();
    }

    /**
     * @throws AuditTenantMismatch
     */
    private function resolveOrganizationId(string $action, ?Model $subject, ?int $explicit): int
    {
        $context = $this->currentOrganization->id();
        $fromSubject = $this->organizationIdOf($subject);

        if ($fromSubject !== null) {
            // Subjekt je zdroj pravdy; context ho smí jen potvrdit.
            if ($context !== null && $context !== $fromSubject) {
                throw AuditTenantMismatch::between($fromSubject, $context);
            }

            if ($explicit !== null && $explicit !== $fromSubject) {
                throw AuditTenantMismatch::between($fromSubject, $explicit);
            }

            return $fromSubject;
        }

        // Bez subjektu (nebo u subjektu bez vlastní organizace) musí
        // organizaci určit volající EXPLICITNĚ — ambientní context nestačí,
        // aby audit nikdy nevznikl „náhodou“ pod aktivním tenantem.
        if ($explicit === null) {
            throw AuditTenantMismatch::missingOrganization($action);
        }

        if ($context !== null && $explicit !== $context) {
            throw AuditTenantMismatch::between($explicit, $context);
        }

        return $explicit;
    }

    private function organizationIdOf(?Model $subject): ?int
    {
        if ($subject === null) {
            return null;
        }

        $value = $subject->getAttribute('organization_id');

        return $value === null ? null : (int) $value;
    }
}
