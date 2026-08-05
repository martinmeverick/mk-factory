<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Contacts\CzechIco;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CzechIcoTest extends TestCase
{
    public function test_normalizes_by_padding_to_eight_digits(): void
    {
        $this->assertSame('00177041', CzechIco::normalize('177041'));
        $this->assertSame('00177041', CzechIco::normalize('00177041'));
        $this->assertSame('00177041', CzechIco::normalize(' 00177041 '));
        $this->assertSame('00177041', CzechIco::normalize('001 770 41'));
    }

    public function test_returns_null_for_values_that_cannot_be_ico(): void
    {
        $this->assertNull(CzechIco::normalize(null));
        $this->assertNull(CzechIco::normalize(''));
        $this->assertNull(CzechIco::normalize('   '));
        $this->assertNull(CzechIco::normalize('abc'));
        $this->assertNull(CzechIco::normalize('CZ12345678'));
        $this->assertNull(CzechIco::normalize('123456789'));
    }

    /**
     * Kontrolní číslice ověřená proti skutečným subjektům v ARESu.
     */
    #[DataProvider('validIcoProvider')]
    public function test_accepts_valid_checksum(string $ico): void
    {
        $this->assertTrue(CzechIco::isValid($ico), "IČO {$ico} mělo projít");
    }

    public static function validIcoProvider(): array
    {
        return [
            'Škoda Auto' => ['00177041'],
            'Centrum volného času' => ['02479273'],
            'Táborská moštárna' => ['04846761'],
            'bez vodicích nul' => ['177041'],
        ];
    }

    #[DataProvider('invalidIcoProvider')]
    public function test_rejects_invalid_checksum(string $ico): void
    {
        $this->assertFalse(CzechIco::isValid($ico), "IČO {$ico} nemělo projít");
    }

    public static function invalidIcoProvider(): array
    {
        return [
            'přehozené číslice' => ['00177014'],
            'vymyšlené' => ['12345678'],
            'samé nuly' => ['00000000'],
            'příliš dlouhé' => ['123456789'],
            'nečíselné' => ['abcdefgh'],
        ];
    }

    public function test_zero_remainder_maps_to_check_digit_one(): void
    {
        // 00177041: součet vah dává zbytek 0 → kontrolní číslice musí být 1.
        $this->assertTrue(CzechIco::isValid('00177041'));
        $this->assertFalse(CzechIco::isValid('00177040'));
    }
}
