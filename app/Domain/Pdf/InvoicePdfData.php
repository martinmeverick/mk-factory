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
     * @param array{name: ?string, ico: ?string, dic: ?string, street: ?string, city: ?string, zip: ?string, country: ?string, email: ?string, phone: ?string, website: ?string} $supplier
     * @param array{name: ?string, ico: ?string, dic: ?string, street: ?string, city: ?string, zip: ?string, country: ?string} $customer
     * @param array{account_number: ?string, bank_code: ?string, iban: ?string, bic: ?string}|null $bankAccount
     * @param list<InvoicePdfLine> $items
     * @param list<array{rate: string, base: Money, vat: Money}> $vatBreakdown
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
        // Režim DPH dokladu; u zvláštního režimu je vatPayer = true (dodavatel
        // je plátce), ale DPH se nevyčísluje a nezobrazuje se rekapitulace.
        public VatRegime $vatRegime = VatRegime::Standard,
    ) {
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
}
