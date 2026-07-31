<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use App\Enums\ContactType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    /** @use HasFactory<\Database\Factories\ContactFactory> */
    use BelongsToOrganization, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
        ];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
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
