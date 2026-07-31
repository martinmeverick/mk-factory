<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceNumberSeries extends Model
{
    /** @use HasFactory<\Database\Factories\InvoiceNumberSeriesFactory> */
    use BelongsToOrganization, HasFactory;

    protected $table = 'invoice_number_series';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'next_number' => 'integer',
        ];
    }

    public function issuedInvoices(): HasMany
    {
        return $this->hasMany(IssuedInvoice::class, 'number_series_id');
    }
}
