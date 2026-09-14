<?php

namespace App\Models;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Domain\Money\Money;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Enums\ReceivedInvoiceStatus;
use Database\Factories\ReceivedInvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ReceivedInvoice extends Model
{
    /** @use HasFactory<ReceivedInvoiceFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * Stavová pole zapisuje VÝHRADNĚ ReceivedInvoiceLifecycle interním
     * zápisem persistLifecycleState() — veřejná cesta (update/save) je
     * nezmění v žádném stavu. Uhrazení tedy nejde předstírat ani vrátit
     * přímým přepsáním stavu.
     */
    public const array LIFECYCLE_ATTRIBUTES = [
        'status',
        'paid_at',
    ];

    /**
     * Finální stavy — JEDINÝ zdroj pravdy pro celou doménu přijatých faktur.
     *
     * Fakturu v tomto stavu už nelze změnit, smazat, ani jí měnit přílohy;
     * rozhodují podle něj model, lifecycle služba, guardy příloh
     * i controller. Dřív se na několika místech testoval jen stav `paid`
     * samostatně, takže zamítnutá faktura šla smazat a její přílohy měnit.
     */
    public const array FINAL_STATUSES = [
        ReceivedInvoiceStatus::Paid,
        ReceivedInvoiceStatus::Rejected,
    ];

    /**
     * Příznak probíhajícího interního zápisu; nastavuje ho pouze private
     * persistLifecycleState(), veřejné přepínání neexistuje.
     */
    private bool $inLifecycleWrite = false;

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

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            // Tenant identita je neměnná bez ohledu na stav.
            if ($invoice->isDirty('organization_id')) {
                throw ImmutableInvoiceViolation::forTenantChange($invoice);
            }

            // Stavová pole mění jen interní zápis lifecycle služby.
            if (! $invoice->inLifecycleWrite) {
                foreach (self::LIFECYCLE_ATTRIBUTES as $attribute) {
                    if ($invoice->isDirty($attribute)) {
                        throw ImmutableInvoiceViolation::forReceivedLifecycleAttribute($invoice, $attribute);
                    }
                }
            }

            // Finalita se posuzuje podle DATABÁZE, ne podle této (možná
            // zastaralé) instance — stará instance jinak po souběžném
            // markPaid() přepíše uhrazený doklad.
            if (self::isFinalStatus($invoice->persistedStatus())) {
                throw ImmutableInvoiceViolation::forFinalReceivedInvoice($invoice);
            }
        });

        static::deleting(function (self $invoice): void {
            // I mazání rozhoduje aktuální stav v DB, ne stav instance —
            // a finální je uhrazená I zamítnutá faktura.
            if (self::isFinalStatus($invoice->persistedStatus())) {
                throw ImmutableInvoiceViolation::forReceivedDeletion($invoice);
            }
        });
    }

    /**
     * Jediné místo, kde se rozhoduje o finalitě stavu. Přijímá i null
     * (neexistující řádek), aby volající nemusel rozlišovat.
     */
    public static function isFinalStatus(?ReceivedInvoiceStatus $status): bool
    {
        return $status !== null && in_array($status, self::FINAL_STATUSES, true);
    }

    /**
     * Finalita podle stavu TÉTO instance. Pro rozhodování o mutaci použij
     * zamčený řádek nebo persistedStatus() — instance může být zastaralá.
     */
    public function isFinal(): bool
    {
        return self::isFinalStatus($this->status);
    }

    /**
     * Aktuální stav podle databáze (nikoli podle této PHP instance).
     * Uvnitř transakce se zamčeným řádkem jde o levné čtení.
     */
    public function persistedStatus(): ?ReceivedInvoiceStatus
    {
        if (! $this->exists) {
            return null;
        }

        $value = static::query()
            ->withoutGlobalScope('organization')
            ->whereKey($this->getKey())
            ->value('status');

        return match (true) {
            $value === null => null,
            $value instanceof ReceivedInvoiceStatus => $value,
            default => ReceivedInvoiceStatus::from((string) $value),
        };
    }

    /**
     * Jediná zápisová cesta stavových polí. PRIVATE schválně — PHPDoc není
     * přístupový modifikátor. ReceivedInvoiceLifecycle se váže přes
     * Closure::bind do scope modelu (obdoba friend třídy).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persistLifecycleState(array $attributes): void
    {
        $unexpected = array_diff(array_keys($attributes), self::LIFECYCLE_ATTRIBUTES);

        if ($unexpected !== []) {
            throw ImmutableInvoiceViolation::forReceivedLifecycleAttribute($this, implode(', ', $unexpected));
        }

        $this->inLifecycleWrite = true;

        try {
            $this->forceFill($attributes)->save();
        } finally {
            $this->inLifecycleWrite = false;
        }
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
