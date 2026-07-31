<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Models\IssuedInvoice;

/**
 * Přepočet řádků a součtů faktury (viz DATA_MODEL.md).
 *
 * Zaokrouhluje se half-up na celé haléře PO ŘÁDCÍCH, součty faktury jsou
 * prostou sumou řádků. Režim neplátce = vat_rate null → DPH 0.
 */
final class InvoiceTotalsCalculator
{
    /**
     * Čistý výpočet jednoho řádku.
     *
     * @return array{subtotal: Money, vat: Money, total: Money}
     */
    public function calculateLine(string $quantity, Money $unitPrice, ?string $vatRate): array
    {
        $subtotal = $unitPrice->multiplyBy($quantity);
        $vat = $vatRate === null
            ? Money::zero($subtotal->getCurrency())
            : $subtotal->percentage($vatRate);

        return [
            'subtotal' => $subtotal,
            'vat' => $vat,
            'total' => $subtotal->plus($vat),
        ];
    }

    /**
     * Přepočítá a uloží všechny položky faktury i součty v hlavičce.
     */
    public function recalculate(IssuedInvoice $invoice): void
    {
        $currency = strtoupper($invoice->currency);

        $subtotal = Money::zero($currency);
        $vatTotal = Money::zero($currency);
        $total = Money::zero($currency);

        $items = $invoice->items()->get();

        foreach ($items as $item) {
            $line = $this->calculateLine(
                (string) $item->quantity,
                Money::fromMinor((int) $item->unit_price_minor, $currency),
                $item->vat_rate === null ? null : (string) $item->vat_rate,
            );

            $item->forceFill([
                'line_subtotal_minor' => $line['subtotal']->getMinor(),
                'line_vat_minor' => $line['vat']->getMinor(),
                'line_total_minor' => $line['total']->getMinor(),
            ])->save();

            $subtotal = $subtotal->plus($line['subtotal']);
            $vatTotal = $vatTotal->plus($line['vat']);
            $total = $total->plus($line['total']);
        }

        $invoice->forceFill([
            'subtotal_minor' => $subtotal->getMinor(),
            'vat_total_minor' => $vatTotal->getMinor(),
            'total_minor' => $total->getMinor(),
        ])->save();

        $invoice->setRelation('items', $items);
    }

    /**
     * Rekapitulace DPH skupinovaná dle sazby (řádky bez sazby — režim
     * neplátce — se do rekapitulace nezahrnují). Klíč = sazba '21.00'.
     *
     * @return array<string, array{rate: string, base: Money, vat: Money, total: Money}>
     */
    public function vatBreakdown(IssuedInvoice $invoice): array
    {
        $currency = strtoupper($invoice->currency);
        $breakdown = [];

        foreach ($invoice->items()->get() as $item) {
            if ($item->vat_rate === null) {
                continue;
            }

            $rate = (string) $item->vat_rate;

            if (! isset($breakdown[$rate])) {
                $breakdown[$rate] = [
                    'rate' => $rate,
                    'base' => Money::zero($currency),
                    'vat' => Money::zero($currency),
                    'total' => Money::zero($currency),
                ];
            }

            $breakdown[$rate]['base'] = $breakdown[$rate]['base']
                ->plus(Money::fromMinor((int) $item->line_subtotal_minor, $currency));
            $breakdown[$rate]['vat'] = $breakdown[$rate]['vat']
                ->plus(Money::fromMinor((int) $item->line_vat_minor, $currency));
            $breakdown[$rate]['total'] = $breakdown[$rate]['total']
                ->plus(Money::fromMinor((int) $item->line_total_minor, $currency));
        }

        uksort($breakdown, fn (string $a, string $b): int => bccomp($b, $a, 2));

        return $breakdown;
    }
}
