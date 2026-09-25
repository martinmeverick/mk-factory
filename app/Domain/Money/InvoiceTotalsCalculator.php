<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Enums\VatRegime;
use App\Models\IssuedInvoice;
use InvalidArgumentException;

/**
 * Přepočet řádků a součtů faktury (viz DATA_MODEL.md).
 *
 * Zaokrouhluje se half-up na celé haléře PO ŘÁDCÍCH, součty faktury jsou
 * prostou sumou řádků. Režim neplátce = vat_rate null → DPH 0.
 *
 * Zvláštní režim - použité zboží (§ 90 ZDPH): unit_price_minor je KONEČNÁ
 * prodejní cena za MJ (vč. DPH z přirážky), běžná DPH řádku je 0
 * (subtotal = total = prodejní cena). DPH se počítá interně jen z kladné
 * přirážky (prodej − pořízení) koeficientem sazba/(100+sazba) a ukládá do
 * oddělených margin_* polí — NIKDY do vat_total_minor / subtotal_minor.
 */
final class InvoiceTotalsCalculator
{
    /**
     * Čistý výpočet jednoho řádku běžného režimu.
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
     * Čistý výpočet jednoho řádku ve zvláštním režimu - použité zboží.
     *
     * - selling (subtotal = total) = quantity × unitPrice (konečná cena)
     * - acquisition = quantity × acquisitionUnitPrice (interní)
     * - margin_gross = max(0, selling − acquisition)
     * - margin_vat = margin_gross × rate / (100 + rate), half-up na haléře
     * - margin_base = margin_gross − margin_vat
     *
     * Při pořizovací ceně vyšší než prodejní je přirážka, DPH i základ 0;
     * prodejní cena (částka k úhradě) zůstává nedotčená.
     *
     * @return array{subtotal: Money, vat: Money, total: Money, acquisition: Money, margin_gross: Money, margin_vat: Money, margin_base: Money}
     */
    public function calculateMarginLine(
        string $quantity,
        Money $unitPrice,
        Money $acquisitionUnitPrice,
        string $marginVatRate,
    ): array {
        $selling = $unitPrice->multiplyBy($quantity);
        $acquisition = $acquisitionUnitPrice->multiplyBy($quantity);
        $currency = $selling->getCurrency();

        $grossMargin = $selling->minus($acquisition);

        if ($grossMargin->isNegative()) {
            $grossMargin = Money::zero($currency);
        }

        $marginVat = $grossMargin->includedVatAtRate($marginVatRate);

        return [
            'subtotal' => $selling,
            'vat' => Money::zero($currency),
            'total' => $selling,
            'acquisition' => $acquisition,
            'margin_gross' => $grossMargin,
            'margin_vat' => $marginVat,
            'margin_base' => $grossMargin->minus($marginVat),
        ];
    }

    /**
     * Přepočítá a uloží všechny položky faktury i součty v hlavičce.
     */
    public function recalculate(IssuedInvoice $invoice): void
    {
        $currency = strtoupper($invoice->currency);
        $isMargin = $invoice->vatRegime() === VatRegime::UsedGoodsMargin;
        $marginRate = null;

        if ($isMargin) {
            $marginRate = $invoice->margin_vat_rate === null ? null : (string) $invoice->margin_vat_rate;

            if (! UsedGoodsMargin::isSupportedRate($marginRate)) {
                throw new InvalidArgumentException(
                    'Zvláštní režim - použité zboží vyžaduje podporovanou interní sazbu DPH z přirážky.'
                );
            }
        }

        $subtotal = Money::zero($currency);
        $vatTotal = Money::zero($currency);
        $total = Money::zero($currency);
        $acquisitionTotal = Money::zero($currency);
        $marginGross = Money::zero($currency);
        $marginVat = Money::zero($currency);
        $marginBase = Money::zero($currency);

        $items = $invoice->items()->orderBy('id')->get();
        $originalTotals = [];
        foreach ($items as $item) {
            $price = Money::fromMinor((int) $item->unit_price_minor, $currency);
            $originalTotals[] = $isMargin
                ? $price->multiplyBy((string) $item->quantity)->getMinor()
                : $this->calculateLine((string) $item->quantity, $price, $item->vat_rate === null ? null : (string) $item->vat_rate)['total']->getMinor();
        }
        // Validate the entire discount before writing any line. The surrounding
        // draft/issue transaction also makes the recalculation atomic.
        $discounts = (new InvoiceDiscount)->allocate(
            $originalTotals,
            (string) ($invoice->discount_type ?? 'none'),
            (string) ($invoice->discount_value ?? '0'),
        );
        $discountTotal = Money::zero($currency);

        foreach ($items as $index => $item) {
            $quantity = (string) $item->quantity;
            $unitPrice = Money::fromMinor((int) $item->unit_price_minor, $currency);
            $discount = Money::fromMinor($discounts[$index], $currency);
            $discountTotal = $discountTotal->plus($discount);

            if ($isMargin) {
                if ($item->acquisition_unit_price_minor === null) {
                    throw new InvalidArgumentException(
                        "Položka „{$item->description}“ nemá zadanou pořizovací cenu (zvláštní režim - použité zboží)."
                    );
                }

                $line = $this->calculateMarginLine(
                    $quantity,
                    $unitPrice,
                    Money::fromMinor((int) $item->acquisition_unit_price_minor, $currency),
                    (string) $marginRate,
                );
                if ($discounts[$index] > 0) {
                    $line['total'] = $line['total']->minus($discount);
                    $line['subtotal'] = $line['total'];
                    $gross = $line['total']->minus($line['acquisition']);
                    $line['margin_gross'] = $gross->isNegative() ? Money::zero($currency) : $gross;
                    $line['margin_vat'] = $line['margin_gross']->includedVatAtRate((string) $marginRate);
                    $line['margin_base'] = $line['margin_gross']->minus($line['margin_vat']);
                }

                $item->forceFill([
                    // Ve zvláštním režimu řádek nenese běžnou sazbu DPH.
                    'vat_rate' => null,
                    'line_subtotal_minor' => $line['subtotal']->getMinor(),
                    'line_vat_minor' => 0,
                    'line_total_minor' => $line['total']->getMinor(),
                    'line_discount_minor' => $discounts[$index],
                    'line_acquisition_minor' => $line['acquisition']->getMinor(),
                    'line_margin_gross_minor' => $line['margin_gross']->getMinor(),
                    'line_margin_vat_minor' => $line['margin_vat']->getMinor(),
                    'line_margin_base_minor' => $line['margin_base']->getMinor(),
                ])->save();

                $acquisitionTotal = $acquisitionTotal->plus($line['acquisition']);
                $marginGross = $marginGross->plus($line['margin_gross']);
                $marginVat = $marginVat->plus($line['margin_vat']);
                $marginBase = $marginBase->plus($line['margin_base']);
            } else {
                $line = $this->calculateLine(
                    $quantity,
                    $unitPrice,
                    $item->vat_rate === null ? null : (string) $item->vat_rate,
                );
                if ($discounts[$index] > 0) {
                    $line['total'] = $line['total']->minus($discount);
                    $line['vat'] = $item->vat_rate === null ? Money::zero($currency)
                        : $line['total']->includedVatAtRate((string) $item->vat_rate);
                    $line['subtotal'] = $line['total']->minus($line['vat']);
                }

                $item->forceFill([
                    'line_subtotal_minor' => $line['subtotal']->getMinor(),
                    'line_vat_minor' => $line['vat']->getMinor(),
                    'line_total_minor' => $line['total']->getMinor(),
                    'line_discount_minor' => $discounts[$index],
                    'line_acquisition_minor' => 0,
                    'line_margin_gross_minor' => 0,
                    'line_margin_vat_minor' => 0,
                    'line_margin_base_minor' => 0,
                ])->save();
            }

            $subtotal = $subtotal->plus($line['subtotal']);
            $vatTotal = $vatTotal->plus($line['vat']);
            $total = $total->plus($line['total']);
        }

        $invoice->forceFill([
            'subtotal_minor' => $subtotal->getMinor(),
            'vat_total_minor' => $vatTotal->getMinor(),
            'total_minor' => $total->getMinor(),
            'discount_total_minor' => $discountTotal->getMinor(),
            'margin_acquisition_total_minor' => $acquisitionTotal->getMinor(),
            'margin_gross_minor' => $marginGross->getMinor(),
            'margin_vat_minor' => $marginVat->getMinor(),
            'margin_base_minor' => $marginBase->getMinor(),
        ])->save();

        $invoice->setRelation('items', $items);
    }

    /**
     * Rekapitulace DPH skupinovaná dle sazby (řádky bez sazby — režim
     * neplátce i zvláštní režim — se do rekapitulace nezahrnují). Klíč = sazba '21.00'.
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
