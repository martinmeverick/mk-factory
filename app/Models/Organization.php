<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    /** @use HasFactory<\Database\Factories\OrganizationFactory> */
    use HasFactory;

    protected $guarded = [];

    public function settings(): HasOne
    {
        return $this->hasOne(OrganizationSettings::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function numberSeries(): HasMany
    {
        return $this->hasMany(InvoiceNumberSeries::class);
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
