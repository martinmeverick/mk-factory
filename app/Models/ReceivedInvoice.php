<?php

namespace App\Models;

use App\Domain\Money\Money;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Enums\ReceivedInvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ReceivedInvoice extends Model
{
    /** @use HasFactory<\Database\Factories\ReceivedInvoiceFactory> */
    use BelongsToOrganization, HasFactory;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'received',
        'currency' => 'CZK',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReceivedInvoiceStatus::class,
            'issue_date' => 'immutable_date',
            'received_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'paid_at' => 'immutable_datetime',
            'total_minor' => 'integer',
            'vat_minor' => 'integer',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ReceivedInvoiceAttachment::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [
                ReceivedInvoiceStatus::Received->value,
                ReceivedInvoiceStatus::Approved->value,
            ])
            ->whereNotNull('due_date')
            ->where('due_date', '<', today()->toDateString());
    }

    /**
     * `overdue` je odvozený stav — neukládá se (viz INVOICE_LIFECYCLE.md).
     */
    public function isOverdue(): bool
    {
        return in_array($this->status, [ReceivedInvoiceStatus::Received, ReceivedInvoiceStatus::Approved], true)
            && $this->due_date !== null
            && $this->due_date->lt(today()->startOfDay());
    }

    protected function displayStatus(): Attribute
    {
        return Attribute::get(
            fn (): string => $this->isOverdue() ? 'overdue' : $this->status->value,
        );
    }

    public function totalMoney(): Money
    {
        return Money::fromMinor($this->total_minor, $this->currency);
    }
}
