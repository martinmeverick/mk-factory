<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ares\AresSubject;
use PHPUnit\Framework\TestCase;

/**
 * Mapování odpovědi ARESu. Vstupní data odpovídají skutečnému tvaru
 * odpovědi registru (ověřeno proti živému API).
 */
class AresSubjectTest extends TestCase
{
    public function test_maps_company_with_named_street(): void
    {
        $subject = AresSubject::fromRegistryData([
            'ico' => '00177041',
            'obchodniJmeno' => 'Škoda Auto a.s.',
            'dic' => 'CZ00177041',
            'sidlo' => [
                'kodStatu' => 'CZ',
                'nazevObce' => 'Mladá Boleslav',
                'nazevUlice' => 'tř. Václava Klementa',
                'cisloDomovni' => 869,
                'psc' => 29301,
                'textovaAdresa' => 'tř. Václava Klementa 869, Mladá Boleslav II, 29301 Mladá Boleslav',
            ],
            'seznamRegistraci' => ['stavZdrojeDph' => 'AKTIVNI'],
        ]);

        $this->assertSame('00177041', $subject->ico);
        $this->assertSame('Škoda Auto a.s.', $subject->name);
        $this->assertSame('CZ00177041', $subject->dic);
        $this->assertTrue($subject->vatPayer);
        $this->assertSame('tř. Václava Klementa 869', $subject->street);
        $this->assertSame('Mladá Boleslav', $subject->city);
        $this->assertSame('293 01', $subject->zip);
        $this->assertSame('CZ', $subject->country);
    }

    public function test_maps_subject_without_street_name_using_house_number(): void
    {
        $subject = AresSubject::fromRegistryData([
            'ico' => '02479273',
            'obchodniJmeno' => 'Centrum volného času Moštárna o.p.s.',
            'dic' => null,
            'sidlo' => [
                'kodStatu' => 'CZ',
                'nazevObce' => 'Hejnice',
                'cisloDomovni' => 602,
                'typCisloDomovni' => 1,
                'psc' => 46362,
                'textovaAdresa' => 'č.p. 602, 46362 Hejnice',
            ],
            'seznamRegistraci' => ['stavZdrojeDph' => 'NEEXISTUJICI'],
        ]);

        $this->assertSame('č.p. 602', $subject->street);
        $this->assertSame('463 62', $subject->zip);
        $this->assertNull($subject->dic);
        $this->assertFalse($subject->vatPayer);
    }

    public function test_includes_orientation_number_when_present(): void
    {
        $subject = AresSubject::fromRegistryData([
            'ico' => '04846761',
            'obchodniJmeno' => 'Táborská moštárna s.r.o.',
            'sidlo' => [
                'nazevUlice' => 'Palackého',
                'cisloDomovni' => 358,
                'cisloOrientacni' => 3,
                'nazevObce' => 'Tábor',
                'psc' => 39001,
            ],
        ]);

        $this->assertSame('Palackého 358/3', $subject->street);
    }

    public function test_uses_evidence_number_prefix(): void
    {
        $subject = AresSubject::fromRegistryData([
            'ico' => '00177041',
            'obchodniJmeno' => 'Chata',
            'sidlo' => ['cisloDomovni' => 12, 'typCisloDomovni' => 2, 'nazevObce' => 'Obec'],
        ]);

        $this->assertSame('č.ev. 12', $subject->street);
    }

    public function test_survives_missing_address_block(): void
    {
        $subject = AresSubject::fromRegistryData([
            'ico' => '00177041',
            'obchodniJmeno' => 'Bez adresy s.r.o.',
        ]);

        $this->assertNull($subject->street);
        $this->assertNull($subject->city);
        $this->assertNull($subject->zip);
        $this->assertSame('CZ', $subject->country);
        $this->assertFalse($subject->vatPayer);
    }

    public function test_normalizes_ico_without_leading_zeros(): void
    {
        $subject = AresSubject::fromRegistryData([
            'ico' => '177041',
            'obchodniJmeno' => 'Test',
        ]);

        $this->assertSame('00177041', $subject->ico);
    }

    public function test_cache_round_trip_preserves_all_fields(): void
    {
        $original = AresSubject::fromRegistryData([
            'ico' => '00177041',
            'obchodniJmeno' => 'Škoda Auto a.s.',
            'dic' => 'CZ00177041',
            'sidlo' => [
                'nazevUlice' => 'tř. Václava Klementa', 'cisloDomovni' => 869,
                'nazevObce' => 'Mladá Boleslav', 'psc' => 29301, 'kodStatu' => 'CZ',
                'textovaAdresa' => 'tř. Václava Klementa 869',
            ],
            'seznamRegistraci' => ['stavZdrojeDph' => 'AKTIVNI'],
        ]);

        $restored = AresSubject::fromCacheArray($original->toCacheArray());

        $this->assertEquals($original, $restored);
    }

    public function test_contact_attributes_contain_only_contact_columns(): void
    {
        $subject = AresSubject::fromRegistryData([
            'ico' => '00177041',
            'obchodniJmeno' => 'Škoda Auto a.s.',
            'sidlo' => ['nazevObce' => 'Mladá Boleslav', 'kodStatu' => 'CZ'],
        ]);

        $this->assertSame(
            ['name', 'ico', 'dic', 'street', 'city', 'zip', 'country'],
            array_keys($subject->toContactAttributes()),
        );
    }
}
