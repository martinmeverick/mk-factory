<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-tenant izolace: globální scope na organization_id (aplikuje se jen
 * když je nastavena aktivní organizace) + auto-fill organization_id při create.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder): void {
            $currentId = app(CurrentOrganization::class)->id();

            if ($currentId !== null) {
                $builder->where(
                    $builder->getModel()->qualifyColumn('organization_id'),
                    $currentId,
                );
            }
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('organization_id') === null) {
                $model->setAttribute('organization_id', app(CurrentOrganization::class)->id());
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
