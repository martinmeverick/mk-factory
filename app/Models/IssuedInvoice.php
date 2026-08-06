<?php

namespace App\Models;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Domain\Money\Money;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Enums\IssuedInvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class IssuedInvoice extends Model
{
    /** @use HasFactory<\Database\Factories\IssuedInvoiceFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * Atributy neměnné po vystavení (viz INVOICE_LIFECYCLE.md).
     */
    public const array PROTECTED_ATTRIBUTES = [
        'invoice_number',
        'variable_symbol',
        'issue_date',
        'due_date',
        'tax_date',
        'contact_id',
        'number_series_id',
        'bank_account_id',
        'currency',
        'subtotal_minor',
        'vat_total_minor',
        'total_minor',
        'supplier_snapshot',
        'customer_snapshot',
        'bank_account_snapshot',
        'footer_text',
        // Tiskne se na fakturu, proto je po vystavení součástí dokladu.
        'note',
        // Logo zmrazené k okamžiku vystavení (historický doklad se nesmí měnit).
        'logo_snapshot_path',
    ];

    /**
     * Lifecycle pole: stav, číslo, úhrady a časy přechodů. Zapisuje je
     * VÝHRADNĚ App\Domain\Invoicing\IssuedInvoiceLifecycle interním zápisem
     * persistLifecycleState() — veřejná cesta (update/save/forceFill+save)
     * je nezmění v ŽÁDNÉM stavu. Koncept proto nelze „vystavit" přímým
     * přepsáním stavu a uhrazení nelze předstírat zápisem paid_amount_minor.
     */
    public const array LIFECYCLE_ATTRIBUTES = [
        'status',
        'invoice_number',
        'paid_amount_minor',
        'issued_at',
        'paid_at',
        'cancelled_at',
    ];

    /**
     * Sjednocený whitelist interního zápisu — víc než tohle lifecycle
     * služba zapsat neumí (organization_id, id ani položky sem nepatří).
     * Úzké vymezení NA OPERACI (vystavení vs. platba vs. storno) drží
     * IssuedInvoiceLifecycle, který pole jednotlivých zápisů natvrdo
     * vyjmenovává.
     */
    private const array LIFECYCLE_WRITABLE = [
        'status',
        'invoice_number',
        'variable_symbol',
        'issue_date',
        'due_date',
        'supplier_snapshot',
        'customer_snapshot',
        'bank_account_snapshot',
        'footer_text',
        'logo_snapshot_path',
        'issued_at',
        'paid_amount_minor',
        'paid_at',
        'cancelled_at',
    ];

    /**
     * Příznak probíhajícího interního zápisu. Nastavuje ho POUZE
     * persistLifecycleState() — je private a žádná veřejná metoda ho
     * nepřepíná, takže guard níže nejde z aplikačního kódu vypnout.
     */
    private bool $inLifecycleWrite = false;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'draft',
        'currency' => 'CZK',
        'subtotal_minor' => 0,
        'vat_total_minor' => 0,
        'total_minor' => 0,
        'paid_amount_minor' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => IssuedInvoiceStatus::class,
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'tax_date' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'subtotal_minor' => 'integer',
            'vat_total_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_amount_minor' => 'integer',
            'supplier_snapshot' => 'array',
            'customer_snapshot' => 'array',
            'bank_account_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            // Tenant identita dokladu je neměnná — bez výjimky a bez ohledu
            // na stav. „Přesun faktury do jiné organizace" jako operace
            // neexistuje.
            if ($invoice->isDirty('organization_id')) {
                throw ImmutableInvoiceViolation::forTenantChange($invoice);
            }

            // Lifecycle pole mění jen interní zápis lifecycle služby.
            if (! $invoice->inLifecycleWrite) {
                foreach (self::LIFECYCLE_ATTRIBUTES as $attribute) {
                    if ($invoice->isDirty($attribute)) {
                        throw ImmutableInvoiceViolation::forLifecycleAttribute($invoice, $attribute);
                    }
                }
            }

            // Stav se čte z DATABÁZE, ne z (potenciálně zastaralé) instance.
            // Jinak by stará draft instance mohla po souběžném vystavení
            // přepsat chráněné údaje už vystaveného dokladu.
            if ($invoice->persistedStatus() === IssuedInvoiceStatus::Draft) {
                return;
            }

            foreach (self::PROTECTED_ATTRIBUTES as $attribute) {
                if ($invoice->isDirty($attribute)) {
                    throw ImmutableInvoiceViolation::forAttribute($invoice, $attribute);
                }
            }
        });

        static::deleting(function (self $invoice): void {
            if ($invoice->persistedStatus() !== IssuedInvoiceStatus::Draft) {
                throw ImmutableInvoiceViolation::forDeletion($invoice);
            }
        });
    }

    /**
     * Aktuální stav podle databáze (nikoli podle této PHP instance).
     * Uvnitř transakce se zamčeným řádkem jde o levné čtení.
     */
    public function persistedStatus(): ?IssuedInvoiceStatus
    {
        if (! $this->exists) {
            return null;
        }

        $value = static::query()
            ->withoutGlobalScope('organization')
            ->whereKey($this->getKey())
            ->value('status');

        // value() vrací hodnotu už přetypovanou castem, ale u raw dotazů
        // může přijít i string — přijmeme obojí.
        return match (true) {
            $value === null => null,
            $value instanceof IssuedInvoiceStatus => $value,
            default => IssuedInvoiceStatus::from((string) $value),
        };
    }

    /**
     * Jediná zápisová cesta lifecycle polí. Je PRIVATE schválně — PHPDoc
     * `@internal` není přístupový modifikátor a dřívější veřejné metody
     * applyIssued()/applyPaymentState()/applyCancelled() šly volat odkudkoli
     * bez validace, zámku i auditu. IssuedInvoiceLifecycle se sem váže přes
     * Closure::bind do scope modelu (obdoba friend třídy); jiná cesta vede
     * jen přes reflexi, kterou v PHP nezastaví žádný guard.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persistLifecycleState(array $attributes): void
    {
        $unexpected = array_diff(array_keys($attributes), self::LIFECYCLE_WRITABLE);

        if ($unexpected !== []) {
            throw ImmutableInvoiceViolation::forAttribute($this, implode(', ', $unexpected));
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

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function numberSeries(): BelongsTo
    {
        return $this->belongsTo(InvoiceNumberSeries::class, 'number_series_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(IssuedInvoiceItem::class)->orderBy('position');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [
                IssuedInvoiceStatus::Issued->value,
                IssuedInvoiceStatus::PartiallyPaid->value,
            ])
            ->whereNotNull('due_date')
            ->where('due_date', '<', today()->toDateString());
    }

    /**
     * `overdue` je odvozený stav — neukládá se (viz INVOICE_LIFECYCLE.md).
     */
    public function isOverdue(): bool
    {
        return in_array($this->status, [IssuedInvoiceStatus::Issued, IssuedInvoiceStatus::PartiallyPaid], true)
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

    public function paidAmountMoney(): Money
    {
        return Money::fromMinor($this->paid_amount_minor, $this->currency);
    }

    public function remainingMoney(): Money
    {
        return $this->totalMoney()->minus($this->paidAmountMoney());
    }

    public function isEditable(): bool
    {
        return $this->status === IssuedInvoiceStatus::Draft;
    }
}
