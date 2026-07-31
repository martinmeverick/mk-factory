<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Money\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Stringable;

/**
 * QR Platba — Short Payment Descriptor (SPD 1.0) dle oficiální
 * specifikace ČBA (https://qr-platba.cz/pro-vyvojare/specifikace-formatu/).
 *
 * Řetězec se sestavuje deterministicky v pořadí klíčů
 * ACC, AM, CC, DT, MSG, X-VS; volitelné klíče se při absenci vynechávají.
 */
final class SpdPayload implements Stringable
{
    private const HEADER = 'SPD*1.0';

    private const MSG_MAX_LENGTH = 60;

    /**
     * Převodní tabulka české diakritiky — iconv //TRANSLIT je závislý na
     * nastavení locale (na macOS bez setlocale selhává), proto se česká
     * písmena převádí napřed vlastní tabulkou a iconv slouží jen jako
     * fallback pro ostatní znaky.
     */
    private const CZECH_TRANSLIT = [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e',
        'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's',
        'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        'Á' => 'A', 'Č' => 'C', 'Ď' => 'D', 'É' => 'E', 'Ě' => 'E',
        'Í' => 'I', 'Ň' => 'N', 'Ó' => 'O', 'Ř' => 'R', 'Š' => 'S',
        'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U', 'Ý' => 'Y', 'Ž' => 'Z',
    ];

    private function __construct(
        private readonly string $iban,
        private readonly Money $amount,
        private readonly ?string $variableSymbol,
        private readonly ?string $message,
        private readonly ?CarbonImmutable $dueDate,
    ) {
    }

    public static function create(
        string $iban,
        Money $amount,
        ?string $variableSymbol = null,
        ?string $message = null,
        ?CarbonImmutable $dueDate = null,
    ): self {
        return new self(
            iban: self::normalizeAndValidateIban($iban),
            amount: self::validateAmount($amount),
            variableSymbol: self::validateVariableSymbol($variableSymbol),
            message: self::sanitizeMessage($message),
            dueDate: $dueDate,
        );
    }

    public function toString(): string
    {
        $parts = [
            self::HEADER,
            'ACC:'.$this->iban,
            'AM:'.$this->amount->toDecimalString(),
            'CC:'.$this->amount->getCurrency(),
        ];

        if ($this->dueDate !== null) {
            $parts[] = 'DT:'.$this->dueDate->format('Ymd');
        }

        if ($this->message !== null) {
            $parts[] = 'MSG:'.$this->message;
        }

        if ($this->variableSymbol !== null) {
            $parts[] = 'X-VS:'.$this->variableSymbol;
        }

        return implode('*', $parts);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private static function normalizeAndValidateIban(string $iban): string
    {
        $normalized = strtoupper(str_replace([' ', "\u{A0}"], '', trim($iban)));

        if ($normalized === '') {
            throw new InvalidArgumentException(
                'Chybí bankovní účet: pro QR Platbu je nutný IBAN.'
            );
        }

        if (! preg_match('/^CZ\d{22}$/', $normalized) || ! CzechIban::isValid($normalized)) {
            throw new InvalidArgumentException(
                "Neplatný IBAN pro QR Platbu: „{$normalized}“."
            );
        }

        return $normalized;
    }

    private static function validateAmount(Money $amount): Money
    {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException(
                'Částka QR Platby musí být kladná: '.$amount->toDecimalString().'.'
            );
        }

        return $amount;
    }

    private static function validateVariableSymbol(?string $variableSymbol): ?string
    {
        if ($variableSymbol === null) {
            return null;
        }

        $variableSymbol = trim($variableSymbol);

        if (! preg_match('/^\d{1,10}$/', $variableSymbol)) {
            throw new InvalidArgumentException(
                "Neplatný variabilní symbol: „{$variableSymbol}“ (povoleno max. 10 číslic)."
            );
        }

        return $variableSymbol;
    }

    /**
     * Zpráva pro příjemce: transliterace do ASCII, velká písmena
     * (doporučení ČBA pro alfanumerický režim QR), bez oddělovače `*`,
     * ořez na 60 znaků. Prázdná zpráva se vynechává.
     */
    private static function sanitizeMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $message = strtr(trim($message), self::CZECH_TRANSLIT);

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $message);
        $message = $transliterated === false ? $message : $transliterated;

        // Jistota čistého ASCII i pro znaky, které iconv nepřevedl.
        $message = (string) preg_replace('/[^\x20-\x7E]/', '', $message);
        $message = str_replace('*', '', $message);
        $message = trim(substr(strtoupper($message), 0, self::MSG_MAX_LENGTH));

        return $message === '' ? null : $message;
    }
}
