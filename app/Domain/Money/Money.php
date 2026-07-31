<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * Immutable peněžní hodnota v celočíselných minor jednotkách (haléře).
 * Veškerá aritmetika přes bcmath / celá čísla — nikdy float.
 */
final class Money implements \JsonSerializable, \Stringable
{
    private function __construct(
        private readonly int $minor,
        private readonly string $currency,
    ) {
    }

    public static function fromMinor(int $minor, string $currency = 'CZK'): self
    {
        return new self($minor, strtoupper($currency));
    }

    /**
     * Přijímá '1234.56' i '1234,56' (max 2 desetinná místa).
     */
    public static function fromDecimalString(string $amount, string $currency = 'CZK'): self
    {
        $normalized = str_replace([' ', "\u{A0}", ','], ['', '', '.'], trim($amount));

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException("Neplatná peněžní částka: {$amount}");
        }

        $negative = str_starts_with($normalized, '-');
        [$units, $cents] = array_pad(explode('.', ltrim($normalized, '-'), 2), 2, '0');
        $minor = ((int) $units) * 100 + (int) str_pad($cents, 2, '0');

        return new self($negative ? -$minor : $minor, strtoupper($currency));
    }

    public static function zero(string $currency = 'CZK'): self
    {
        return new self(0, strtoupper($currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /**
     * Vynásobí desetinným množstvím (např. '2.500'), half-up na celé haléře.
     */
    public function multiplyBy(string $decimalQuantity): self
    {
        $qty = str_replace(',', '.', trim($decimalQuantity));

        if (! preg_match('/^-?\d+(\.\d+)?$/', $qty)) {
            throw new InvalidArgumentException("Neplatné množství: {$decimalQuantity}");
        }

        $product = bcmul((string) $this->minor, $qty, 6);

        return new self(self::roundHalfUpToInt($product), $this->currency);
    }

    /**
     * Procentní část (např. sazba DPH '21.00'), half-up na celé haléře.
     */
    public function percentage(string $rate): self
    {
        $rate = str_replace(',', '.', trim($rate));

        if (! preg_match('/^\d+(\.\d+)?$/', $rate)) {
            throw new InvalidArgumentException("Neplatná sazba: {$rate}");
        }

        $product = bcdiv(bcmul((string) $this->minor, $rate, 6), '100', 6);

        return new self(self::roundHalfUpToInt($product), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor >= $other->minor;
    }

    public function getMinor(): int
    {
        return $this->minor;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Strojový zápis s tečkou a dvěma desetinnými místy: '1234.50'.
     */
    public function toDecimalString(): string
    {
        $abs = abs($this->minor);
        $sign = $this->minor < 0 ? '-' : '';

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /**
     * České formátování: '1 234,56 Kč' (nezlomitelné mezery).
     */
    public function formatCzech(): string
    {
        $abs = abs($this->minor);
        $sign = $this->minor < 0 ? '−' : '';
        $units = number_format(intdiv($abs, 100), 0, ',', "\u{A0}");
        $symbol = $this->currency === 'CZK' ? 'Kč' : $this->currency;

        return sprintf("%s%s,%02d\u{A0}%s", $sign, $units, $abs % 100, $symbol);
    }

    public function jsonSerialize(): array
    {
        return ['minor' => $this->minor, 'currency' => $this->currency];
    }

    public function __toString(): string
    {
        return $this->formatCzech();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Operace nad různými měnami: {$this->currency} vs {$other->currency}"
            );
        }
    }

    /**
     * Half-up (od nuly) zaokrouhlení bcmath řetězce na celé číslo.
     */
    private static function roundHalfUpToInt(string $decimal): int
    {
        $adjusted = bcadd($decimal, str_starts_with($decimal, '-') ? '-0.5' : '0.5', 6);

        return (int) bcdiv($adjusted, '1', 0);
    }
}
