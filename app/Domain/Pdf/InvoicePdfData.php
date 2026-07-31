<?php

declare(strict_types=1);

namespace App\Domain\Pdf;

use App\Domain\Money\Money;
use DateTimeImmutable;

/**
 * Kompletní data pro PDF šablonu faktury. Čisté readonly DTO — šablona
 * ani renderer NEsahají na Eloquent; mapper (InvoicePdfDataFactory)
 * sestavuje DTO z modelu mimo tuto vrstvu.
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
    ) {
    }

    public function isDraft(): bool
    {
        return $this->invoiceNumber === null;
    }
}
