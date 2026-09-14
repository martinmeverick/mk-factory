<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Režim DPH vydané faktury (sloupec issued_invoices.vat_regime).
 *
 * - standard: dosavadní chování — plátce (položky se sazbou) nebo neplátce
 *   (položky bez sazby) dle nastavení organizace v době vytvoření položek.
 * - used_goods_margin: zvláštní režim - použité zboží (§ 90 ZDPH). Dodavatel
 *   je plátce, DPH se počítá interně jen z přirážky a na dokladu se nevyčísluje.
 */
enum VatRegime: string
{
    case Standard = 'standard';
    case UsedGoodsMargin = 'used_goods_margin';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Běžný režim DPH',
            self::UsedGoodsMargin => 'Zvláštní režim - použité zboží (§ 90 ZDPH)',
        };
    }

    public function isUsedGoodsMargin(): bool
    {
        return $this === self::UsedGoodsMargin;
    }
}
