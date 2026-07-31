<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Money\Money;
use App\Domain\Payments\SpdPayload;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SpdPayloadTest extends TestCase
{
    private const VALID_IBAN = 'CZ1801000000000123456789';

    public function test_full_payload_has_deterministic_key_order(): void
    {
        $payload = SpdPayload::create(
            iban: self::VALID_IBAN,
            amount: Money::fromMinor(123450, 'CZK'),
            variableSymbol: '20260007',
            message: 'Faktura FV20260007',
            dueDate: CarbonImmutable::parse('2026-08-15'),
        );

        $this->assertSame(
            'SPD*1.0*ACC:CZ1801000000000123456789*AM:1234.50*CC:CZK'
            .'*DT:20260815*MSG:FAKTURA FV20260007*X-VS:20260007',
            $payload->toString(),
        );
    }

    public function test_to_string_magic_method_matches_to_string(): void
    {
        $payload = SpdPayload::create(self::VALID_IBAN, Money::fromMinor(45000));

        $this->assertSame($payload->toString(), (string) $payload);
    }

    public function test_amount_is_formatted_with_two_decimals_from_money(): void
    {
        $payload = SpdPayload::create(self::VALID_IBAN, Money::fromMinor(45000, 'CZK'));

        $this->assertStringContainsString('*AM:450.00*', $payload->toString());
    }

    public function test_amount_with_halere(): void
    {
        $payload = SpdPayload::create(self::VALID_IBAN, Money::fromMinor(123450, 'CZK'));

        $this->assertStringContainsString('*AM:1234.50*', $payload->toString());
    }

    public function test_amount_with_rounded_halere_from_multiplication(): void
    {
        // 3 × 33,33 = 99,99 → přesné haléře, žádný float
        $amount = Money::fromDecimalString('33.33')->multiplyBy('3');

        $payload = SpdPayload::create(self::VALID_IBAN, $amount);

        $this->assertStringContainsString('*AM:99.99*', $payload->toString());
    }

    public function test_currency_code_is_taken_from_money(): void
    {
        $payload = SpdPayload::create(self::VALID_IBAN, Money::fromMinor(10000, 'EUR'));

        $this->assertStringContainsString('*CC:EUR', $payload->toString());
    }

    public function test_zero_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SpdPayload::create(self::VALID_IBAN, Money::zero());
    }

    public function test_negative_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SpdPayload::create(self::VALID_IBAN, Money::fromMinor(-100));
    }

    public function test_iban_is_normalized_from_spaces_and_lowercase(): void
    {
        $payload = SpdPayload::create('  cz18 0100 0000 0001 2345 6789 ', Money::fromMinor(100));

        $this->assertStringContainsString('*ACC:CZ1801000000000123456789*', $payload->toString());
    }

    public function test_invalid_iban_checksum_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SpdPayload::create('CZ1801000000000123456780', Money::fromMinor(100));
    }

    public function test_non_czech_iban_format_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // validní německý IBAN — kontrakt vyžaduje CZ formát (24 znaků)
        SpdPayload::create('DE89370400440532013000', Money::fromMinor(100));
    }

    public function test_empty_iban_is_rejected_as_missing_bank_account(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chybí bankovní účet');

        SpdPayload::create('   ', Money::fromMinor(100));
    }

    public function test_variable_symbol_with_non_digits_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SpdPayload::create(self::VALID_IBAN, Money::fromMinor(100), variableSymbol: '12A45');
    }

    public function test_variable_symbol_longer_than_ten_digits_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SpdPayload::create(self::VALID_IBAN, Money::fromMinor(100), variableSymbol: '12345678901');
    }

    public function test_null_variable_symbol_is_omitted(): void
    {
        $payload = SpdPayload::create(self::VALID_IBAN, Money::fromMinor(100));

        $this->assertStringNotContainsString('X-VS', $payload->toString());
    }

    public function test_message_czech_diacritics_are_transliterated_to_ascii(): void
    {
        $payload = SpdPayload::create(
            self::VALID_IBAN,
            Money::fromMinor(100),
            message: 'Příliš žluťoučký kůň úpěl ďábelské ódy',
        );

        $this->assertStringContainsString(
            'MSG:PRILIS ZLUTOUCKY KUN UPEL DABELSKE ODY',
            $payload->toString(),
        );
    }

    public function test_message_asterisks_are_removed(): void
    {
        $payload = SpdPayload::create(
            self::VALID_IBAN,
            Money::fromMinor(100),
            message: 'Platba *faktury* 7',
        );

        $this->assertStringContainsString('MSG:PLATBA FAKTURY 7', $payload->toString());
    }

    public function test_message_is_trimmed_to_sixty_characters(): void
    {
        $payload = SpdPayload::create(
            self::VALID_IBAN,
            Money::fromMinor(100),
            message: str_repeat('A', 75),
        );

        $this->assertStringContainsString('MSG:'.str_repeat('A', 60).'*', $payload->toString().'*');
        $this->assertStringNotContainsString(str_repeat('A', 61), $payload->toString());
    }

    public function test_null_and_empty_message_is_omitted(): void
    {
        $withNull = SpdPayload::create(self::VALID_IBAN, Money::fromMinor(100), message: null);
        $withEmpty = SpdPayload::create(self::VALID_IBAN, Money::fromMinor(100), message: '   ');

        $this->assertStringNotContainsString('MSG', $withNull->toString());
        $this->assertStringNotContainsString('MSG', $withEmpty->toString());
    }

    public function test_due_date_is_formatted_as_yyyymmdd(): void
    {
        $payload = SpdPayload::create(
            self::VALID_IBAN,
            Money::fromMinor(100),
            dueDate: CarbonImmutable::parse('2026-01-05'),
        );

        $this->assertStringContainsString('*DT:20260105', $payload->toString());
    }

    public function test_null_due_date_is_omitted(): void
    {
        $payload = SpdPayload::create(self::VALID_IBAN, Money::fromMinor(100));

        $this->assertStringNotContainsString('DT:', $payload->toString());
    }

    public function test_minimal_payload_contains_only_required_keys(): void
    {
        $payload = SpdPayload::create(self::VALID_IBAN, Money::fromMinor(45000, 'CZK'));

        $this->assertSame(
            'SPD*1.0*ACC:CZ1801000000000123456789*AM:450.00*CC:CZK',
            $payload->toString(),
        );
    }
}
