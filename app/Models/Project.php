<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use BelongsToOrganization, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function issuedInvoices(): HasMany
    {
        return $this->hasMany(IssuedInvoice::class);
    }

    public function receivedInvoices(): HasMany
    {
        return $this->hasMany(ReceivedInvoice::class);
    }
}
