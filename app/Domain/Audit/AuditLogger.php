<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class AuditLogger
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
    ) {
    }

    public function log(string $action, ?Model $subject = null, array $changes = []): void
    {
        $log = new AuditLog([
            'organization_id' => $this->currentOrganization->id(),
            'user_id' => Auth::id(),
            'action' => $action,
            'changes' => $changes === [] ? null : $changes,
        ]);

        if ($subject !== null) {
            $log->subject()->associate($subject);
        }

        $log->save();
    }
}
