<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Payments\CzechIban;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CzechIbanTest extends TestCase
{
    public function test_from_czech_account_known_value_kb(): void
    {
        $this->assertSame(
            'CZ1801000000000123456789',
            CzechIban::fromCzechAccount(null, '123456789', '0100'),
        );
    }

    public function test_from_czech_account_known_value_csob(): void
    {
        $this->assertSame(
            'CZ1403000000000987654321',
            CzechIban::fromCzechAccount(null, '987654321', '0300'),
        );
    }

    public function test_from_czech_account_with_prefix(): void
    {
        // oficiální příklad z dokumentace ČNB: 19-2000145399/0800
        $this->assertSame(
            'CZ6508000000192000145399',
            CzechIban::fromCzechAccount('19', '2000145399', '0800'),
        );
    }

    public function test_from_account_number_parses_prefix_format(): void
    {
        $this->assertSame(
            'CZ6508000000192000145399',
            CzechIban::fromAccountNumber('19-2000145399', '0800'),
        );
    }

    public function test_from_account_number_without_prefix(): void
    {
        $this->assertSame(
            'CZ1801000000000123456789',
            CzechIban::fromAccountNumber('123456789', '0100'),
        );
    }

    public function test_generated_ibans_pass_validation(): void
    {
        $this->assertTrue(CzechIban::isValid(CzechIban::fromAccountNumber('19-2000145399', '0800')));
        $this->assertTrue(CzechIban::isValid(CzechIban::fromCzechAccount('', '5', '5500')));
    }

    public function test_is_valid_accepts_known_good_ibans(): void
    {
        $this->assertTrue(CzechIban::isValid('CZ1801000000000123456789'));
        $this->assertTrue(CzechIban::isValid('CZ1403000000000987654321'));
        $this->assertTrue(CzechIban::isValid('cz18 0100 0000 0001 2345 6789'));
        $this->assertTrue(CzechIban::isValid('DE89370400440532013000'));
    }

    public function test_is_valid_rejects_bad_checksum(): void
    {
        $this->assertFalse(CzechIban::isValid('CZ1801000000000123456780'));
        $this->assertFalse(CzechIban::isValid('CZ9901000000000123456789'));
    }

    public function test_is_valid_rejects_wrong_length_and_garbage(): void
    {
        $this->assertFalse(CzechIban::isValid(''));
        $this->assertFalse(CzechIban::isValid('CZ18'));
        $this->assertFalse(CzechIban::isValid('CZ180100000000012345678'));   // 23 znaků
        $this->assertFalse(CzechIban::isValid('CZ18010000000001234567890')); // 25 znaků
        $this->assertFalse(CzechIban::isValid('nesmysl'));
    }

    public function test_prefix_longer_than_six_digits_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CzechIban::fromCzechAccount('1234567', '123', '0100');
    }

    public function test_number_longer_than_ten_digits_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CzechIban::fromCzechAccount(null, '12345678901', '0100');
    }

    public function test_non_digit_account_number_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CzechIban::fromCzechAccount(null, '12a45', '0100');
    }

    public function test_bank_code_must_be_exactly_four_digits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CzechIban::fromCzechAccount(null, '123456789', '100');
    }

    public function test_empty_account_number_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CzechIban::fromCzechAccount(null, '', '0100');
    }
}
