<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationSettings extends Model
{
    /** @use HasFactory<\Database\Factories\OrganizationSettingsFactory> */
    use BelongsToOrganization, HasFactory;

    protected $table = 'organization_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'vat_payer' => 'boolean',
            'default_due_days' => 'integer',
        ];
    }

    public function defaultBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'default_bank_account_id');
    }

    public function defaultNumberSeries(): BelongsTo
    {
        return $this->belongsTo(InvoiceNumberSeries::class, 'default_number_series_id');
    }
}
