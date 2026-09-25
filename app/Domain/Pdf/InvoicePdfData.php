<?php

declare(strict_types=1);

namespace App\Domain\Pdf;

use App\Domain\Money\Money;
use App\Domain\Money\UsedGoodsMargin;
use App\Enums\VatRegime;
use DateTimeImmutable;

/**
 * Kompletní data pro PDF šablonu faktury. Čisté readonly DTO — šablona
 * ani renderer NEsahají na Eloquent; mapper (InvoicePdfDataFactory)
 * sestavuje DTO z modelu mimo tuto vrstvu.
 *
 * DTO záměrně NEOBSAHUJE pořizovací ceny, přirážku ani DPH z přirážky —
 * interní evidence zvláštního režimu se na doklad odběratele nikdy nedostane.
 */
final readonly class InvoicePdfData
{
    /**
     * @param  array{name: ?string, ico: ?string, dic: ?string, street: ?string, city: ?string, zip: ?string, country: ?string, email: ?string, phone: ?string, website: ?string}  $supplier
     * @param  array{name: ?string, ico: ?string, dic: ?string, street: ?string, city: ?string, zip: ?string, country: ?string}  $customer
     * @param  array{account_number: ?string, bank_code: ?string, iban: ?string, bic: ?string}|null  $bankAccount
     * @param  list<InvoicePdfLine>  $items
     * @param  list<array{rate: string, base: Money, vat: Money}>  $vatBreakdown
     */
    public function __construct(
        public array $supplier,
        public array $customer,
        public ?string $invoiceNumber,   // null => koncept (nedaňový doklad)
        public ?string $variableSymbol,
        public DateTimeImmutable $issueDate,
        public DateTimeImmutable $dueDate,
        public ?DateTimeImmutable $taxDate,
        public ?array $bankAccount,
        public array $items,
        public Money $subtotal,
        public array $vatBreakdown,
        public Money $total,
        public bool $vatPayer,
        public ?string $note,
        public ?string $footerText,
        public ?string $logoDataUri,
        public ?string $qrDataUri,
        public string $status = 'draft',
        public ?Money $paidAmount = null,
        public ?Money $remainingAmount = null,
        // Režim DPH dokladu; u zvláštního režimu je vatPayer = true (dodavatel
        // je plátce), ale DPH se nevyčísluje a nezobrazuje se rekapitulace.
        public VatRegime $vatRegime = VatRegime::Standard,
        public string $discountType = 'none',
        public string $discountValue = '0',
        public ?Money $discountTotal = null,
    ) {}

    public function hasDiscount(): bool
    {
        return $this->discountTotal?->isPositive() ?? false;
    }

    public function originalTotal(): Money
    {
        return $this->discountTotal === null ? $this->total : $this->total->plus($this->discountTotal);
    }

    public function discountLabel(): string
    {
        if ($this->discountType !== 'percent') {
            return 'Sleva';
        }

        $value = str_replace(',', '.', $this->discountValue);
        $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

        return 'Sleva '.str_replace('.', ',', $value).' %';
    }

    public function isDraft(): bool
    {
        return $this->invoiceNumber === null;
    }

    public function isUsedGoodsMargin(): bool
    {
        return $this->vatRegime === VatRegime::UsedGoodsMargin;
    }

    /**
     * Sloupce a rekapitulace DPH se tisknou jen u běžného režimu plátce.
     */
    public function showsVatColumns(): bool
    {
        return $this->vatPayer && ! $this->isUsedGoodsMargin();
    }

    /**
     * Povinný text zvláštního režimu (§ 90 odst. 14 ZDPH) — přesné znění.
     */
    public function usedGoodsMarginNotice(): string
    {
        return UsedGoodsMargin::CUSTOMER_NOTICE;
    }

    public function usedGoodsMarginNoticeSupplement(): string
    {
        return UsedGoodsMargin::CUSTOMER_NOTICE_SUPPLEMENT;
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isPartiallyPaid(): bool
    {
        return $this->status === 'partially_paid';
    }

    /**
     * Zbývá-li něco uhradit, je to částka k zaplacení; jinak nula.
     */
    public function amountDue(): Money
    {
        return $this->remainingAmount ?? $this->total;
    }

    /**
     * Popisek u výsledné částky. U uhrazené či stornované faktury nesmí
     * tvrdit, že je původní obnos stále splatný.
     */
    public function totalLabel(): string
    {
        return match (true) {
            $this->isCancelled() => 'Celkem (doklad stornován)',
            $this->isPaid() => 'Celkem (uhrazeno)',
            $this->isPartiallyPaid() => 'Zbývá k úhradě',
            default => 'Celkem k úhradě',
        };
    }

    /**
     * Výrazný stavový štítek do hlavičky dokladu; null = nic se nezobrazuje.
     */
    public function statusBanner(): ?string
    {
        return match ($this->status) {
            'cancelled' => 'STORNOVÁNO',
            'paid' => 'UHRAZENO',
            'partially_paid' => 'ČÁSTEČNĚ UHRAZENO',
            default => null,
        };
    }
}
