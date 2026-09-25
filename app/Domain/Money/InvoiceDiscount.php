<?php

declare(strict_types=1);

namespace App\Domain\Money;

/** Whole-invoice gross discount, allocated in minor units with stable remainders. */
final class InvoiceDiscount
{
    /** @param list<int> $grossAmounts @return list<int> */
    public function allocate(array $grossAmounts, string $type, string $value): array
    {
        if (! in_array($type, ['none', 'percent', 'fixed'], true)
            || ! preg_match('/^\d{1,17}(\.\d{1,2})?$/D', $value)) {
            throw new InvalidInvoiceDiscount('Neplatný typ nebo hodnota slevy.');
        }
        $zero = array_fill(0, count($grossAmounts), 0);
        if ($type === 'none') {
            if (bccomp($value, '0', 2) !== 0) {
                throw new InvalidInvoiceDiscount('Při volbě Bez slevy musí být sleva nulová.');
            }

            return $zero;
        }
        if ($type === 'percent' && bccomp($value, '100', 2) > 0) {
            throw new InvalidInvoiceDiscount('Sleva nemůže být vyšší než 100 %.');
        }
        $sum = Money::zero();
        foreach ($grossAmounts as $gross) {
            if ($gross < 0) {
                throw new InvalidInvoiceDiscount('Slevu z celé faktury lze použít jen u nezáporných položek.');
            }
            $sum = $sum->plus(Money::fromMinor($gross));
        }
        $discount = ($type === 'percent' ? $sum->percentage($value) : Money::fromDecimalString($value))->getMinor();
        $total = $sum->getMinor();
        if ($discount > $total) {
            throw new InvalidInvoiceDiscount('Sleva nesmí přesáhnout cenu celé faktury.');
        }
        if ($discount === 0 || $total === 0) {
            return $zero;
        }
        $allocated = [];
        $remainders = [];
        $remaining = $discount;
        foreach ($grossAmounts as $index => $gross) {
            $product = bcmul((string) $gross, (string) $discount, 0);
            $part = (int) bcdiv($product, (string) $total, 0);
            $allocated[$index] = $part;
            $remaining -= $part;
            $remainders[$index] = bcmod($product, (string) $total);
        }
        uksort($remainders, fn (int $a, int $b): int => bccomp($remainders[$b], $remainders[$a], 0) ?: $a <=> $b);
        foreach ($remainders as $index => $_) {
            if ($remaining === 0) {
                break;
            }
            $allocated[$index]++;
            $remaining--;
        }

        return $allocated;
    }
}
