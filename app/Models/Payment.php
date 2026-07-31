<?php

namespace App\Models;

use App\Domain\Money\Money;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use BelongsToOrganization, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'paid_on' => 'immutable_date',
        ];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function amountMoney(): Money
    {
        return Money::fromMinor($this->amount_minor, $this->currency);
    }
}
