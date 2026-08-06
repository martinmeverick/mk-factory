<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * Immutable peněžní hodnota v celočíselných minor jednotkách (haléře).
 * Veškerá aritmetika přes bcmath / celá čísla — nikdy float.
 *
 * Každý převod bcmath řetězce na PHP int prochází kontrolou rozsahu
 * (viz assertWithinRange). Bez ní by `(int)` saturoval na PHP_INT_MAX
 * a do databáze by se uložila nesmyslná částka místo chyby.
 */
final class Money implements \JsonSerializable, \Stringable
{
    /**
     * Horní mez haléřů — signed BIGINT i PHP int mají stejné maximum.
     */
    public const string MAX_MINOR = '9223372036854775807';

    public const string MIN_MINOR = '-9223372036854775808';

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
     *
     * @throws MoneyOverflow když se částka nevejde do rozsahu
     */
    public static function fromDecimalString(string $amount, string $currency = 'CZK'): self
    {
        $normalized = str_replace([' ', "\u{A0}", ','], ['', '', '.'], trim($amount));

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException("Neplatná peněžní částka: {$amount}");
        }

        $negative = str_starts_with($normalized, '-');
        [$units, $cents] = array_pad(explode('.', ltrim($normalized, '-'), 2), 2, '0');

        // bcmath, ne (int) — '9999999999999999999' by se jinak ořízlo.
        $minorDecimal = bcadd(bcmul($units, '100', 0), str_pad($cents, 2, '0'), 0);

        if ($negative) {
            $minorDecimal = '-'.$minorDecimal;
        }

        return new self(self::toIntChecked($minorDecimal, 'převod částky'), strtoupper($currency));
    }

    public static function zero(string $currency = 'CZK'): self
    {
        return new self(0, strtoupper($currency));
    }

    /**
     * @throws MoneyOverflow když součet přeteče rozsah
     */
    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        $sum = bcadd((string) $this->minor, (string) $other->minor, 0);

        return new self(self::toIntChecked($sum, 'součet'), $this->currency);
    }

    /**
     * @throws MoneyOverflow když rozdíl přeteče rozsah
     */
    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        $difference = bcsub((string) $this->minor, (string) $other->minor, 0);

        return new self(self::toIntChecked($difference, 'rozdíl'), $this->currency);
    }

    /**
     * Vynásobí desetinným množstvím (např. '2.500'), half-up na celé haléře.
     *
     * @throws MoneyOverflow když součin přeteče rozsah
     */
    public function multiplyBy(string $decimalQuantity): self
    {
        $qty = str_replace(',', '.', trim($decimalQuantity));

        if (! preg_match('/^-?\d+(\.\d+)?$/', $qty)) {
            throw new InvalidArgumentException("Neplatné množství: {$decimalQuantity}");
        }

        $product = bcmul((string) $this->minor, $qty, 6);

        return new self(self::roundHalfUpToInt($product, 'součin množství a ceny'), $this->currency);
    }

    /**
     * Procentní část (např. sazba DPH '21.00'), half-up na celé haléře.
     *
     * @throws MoneyOverflow když výsledek přeteče rozsah
     */
    public function percentage(string $rate): self
    {
        $rate = str_replace(',', '.', trim($rate));

        if (! preg_match('/^\d+(\.\d+)?$/', $rate)) {
            throw new InvalidArgumentException("Neplatná sazba: {$rate}");
        }

        $product = bcdiv(bcmul((string) $this->minor, $rate, 6), '100', 6);

        return new self(self::roundHalfUpToInt($product, 'výpočet DPH'), $this->currency);
    }

    /**
     * Ověří, že desetinný řetězec haléřů leží v podporovaném rozsahu.
     * Volá se PŘED castem na int — to je celý smysl.
     *
     * @throws MoneyOverflow
     */
    public static function assertWithinRange(string $decimalMinor, string $operation = 'hodnota'): void
    {
        $whole = self::truncateToWhole($decimalMinor);

        if (bccomp($whole, self::MAX_MINOR, 0) > 0 || bccomp($whole, self::MIN_MINOR, 0) < 0) {
            throw MoneyOverflow::forValue($whole, $operation);
        }
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
        [$negative, $units, $cents] = $this->decimalParts();

        return sprintf('%s%s.%s', $negative ? '-' : '', $units, $cents);
    }

    /**
     * České formátování: '1 234,56 Kč' (nezlomitelné mezery).
     */
    public function formatCzech(): string
    {
        [$negative, $units, $cents] = $this->decimalParts();
        $symbol = $this->currency === 'CZK' ? 'Kč' : $this->currency;

        return sprintf(
            "%s%s,%s\u{A0}%s",
            $negative ? '−' : '',
            self::groupThousands($units),
            $cents,
            $symbol,
        );
    }

    public function jsonSerialize(): array
    {
        return ['minor' => $this->minor, 'currency' => $this->currency];
    }

    public function __toString(): string
    {
        return $this->formatCzech();
    }

    /**
     * Rozklad na znaménko, celé jednotky a dvouciferné haléře ČISTĚ přes
     * řetězce. `abs()`/`intdiv()` tu nesmí být: abs(PHP_INT_MIN) se do int
     * nevejde, přeteče do floatu a formátování skončí chybou nebo
     * scientific notation.
     *
     * @return array{0: bool, 1: string, 2: string}
     */
    private function decimalParts(): array
    {
        $minor = (string) $this->minor;
        $negative = str_starts_with($minor, '-');
        $digits = str_pad(ltrim($minor, '-'), 3, '0', STR_PAD_LEFT);

        return [$negative, substr($digits, 0, -2), substr($digits, -2)];
    }

    /**
     * Tisícové skupiny zprava, oddělené nezlomitelnou mezerou. Vlastní
     * implementace místo number_format(): ta bere int/float a na hraně
     * rozsahu by vyžadovala právě ten převod, kterému se vyhýbáme.
     */
    private static function groupThousands(string $digits): string
    {
        $groups = [];

        for ($end = strlen($digits); $end > 0; $end -= 3) {
            $start = max(0, $end - 3);
            array_unshift($groups, substr($digits, $start, $end - $start));
        }

        return implode("\u{A0}", $groups);
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
     * Half-up (od nuly) zaokrouhlení bcmath řetězce na celé číslo,
     * s kontrolou rozsahu před castem.
     *
     * @throws MoneyOverflow
     */
    private static function roundHalfUpToInt(string $decimal, string $operation): int
    {
        $adjusted = bcadd($decimal, str_starts_with($decimal, '-') ? '-0.5' : '0.5', 6);

        return self::toIntChecked(bcdiv($adjusted, '1', 0), $operation);
    }

    /**
     * Jediné místo, kde se bcmath řetězec převádí na int — vždy po kontrole.
     *
     * @throws MoneyOverflow
     */
    private static function toIntChecked(string $decimalMinor, string $operation): int
    {
        self::assertWithinRange($decimalMinor, $operation);

        return (int) self::truncateToWhole($decimalMinor);
    }

    /**
     * Odřízne desetinnou část bez zaokrouhlení (bcmath řetězce mohou mít
     * scale i po bcdiv se scale 0 na některých platformách).
     */
    private static function truncateToWhole(string $decimal): string
    {
        $position = strpos($decimal, '.');

        return $position === false ? $decimal : substr($decimal, 0, $position);
    }
}
