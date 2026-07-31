<?php

declare(strict_types=1);

namespace App\Domain\Pdf;

use App\Domain\Money\Money;

/**
 * Jeden řádek faktury pro PDF šablonu. Čisté DTO — bez vazby na Eloquent.
 */
final readonly class InvoicePdfLine
{
    public function __construct(
        public string $description,
        public string $quantity,      // surový decimál, např. '2.500'
        public string $unit,
        public Money $unitPrice,
        public ?string $vatRate,      // '21.00' | null (režim neplátce)
        public Money $lineSubtotal,
        public Money $lineVat,
        public Money $lineTotal,
    ) {
    }

    /**
     * Množství pro tisk: bez koncových nul, s desetinnou čárkou
     * ('2.500' → '2,5'; '1.000' → '1').
     */
    public function quantityFormatted(): string
    {
        $quantity = str_replace(',', '.', trim($this->quantity));

        if (str_contains($quantity, '.')) {
            $quantity = rtrim(rtrim($quantity, '0'), '.');
        }

        return str_replace('.', ',', $quantity === '' ? '0' : $quantity);
    }

    /**
     * Sazba DPH pro tisk: bez koncových nul, např. '21.00' → '21'.
     */
    public function vatRateFormatted(): string
    {
        if ($this->vatRate === null) {
            return '—';
        }

        $rate = str_replace(',', '.', trim($this->vatRate));

        if (str_contains($rate, '.')) {
            $rate = rtrim(rtrim($rate, '0'), '.');
        }

        return str_replace('.', ',', $rate === '' ? '0' : $rate);
    }
}
