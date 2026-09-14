<?php

declare(strict_types=1);

namespace App\Domain\Ares;

use App\Domain\Contacts\CzechIco;

/**
 * Ekonomický subjekt načtený z ARESu — jen ta podmnožina údajů, kterou
 * aplikace potřebuje pro kontakt. Nezná Eloquent ani HTTP.
 */
final readonly class AresSubject
{
    public function __construct(
        public string $ico,
        public string $name,
        public ?string $dic,
        public bool $vatPayer,
        public ?string $street,
        public ?string $city,
        public ?string $zip,
        public string $country,
        public ?string $textAddress,
    ) {
    }

    /**
     * Mapuje odpověď ARESu (detail i položku vyhledávání — mají stejný tvar).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromRegistryData(array $data): self
    {
        $address = is_array($data['sidlo'] ?? null) ? $data['sidlo'] : [];
        $registrations = is_array($data['seznamRegistraci'] ?? null) ? $data['seznamRegistraci'] : [];

        return new self(
            ico: CzechIco::normalize((string) ($data['ico'] ?? '')) ?? (string) ($data['ico'] ?? ''),
            name: trim((string) ($data['obchodniJmeno'] ?? '')),
            dic: self::nullableString($data['dic'] ?? null),
            // ARES vrací stav registrace v registru plátců DPH.
            vatPayer: ($registrations['stavZdrojeDph'] ?? null) === 'AKTIVNI',
            street: self::composeStreet($address),
            city: self::nullableString($address['nazevObce'] ?? null),
            zip: self::formatZip($address['psc'] ?? null),
            country: (string) ($address['kodStatu'] ?? 'CZ'),
            textAddress: self::nullableString($address['textovaAdresa'] ?? null),
        );
    }

    /**
     * Atributy pro založení/aktualizaci kontaktu.
     *
     * @return array<string, mixed>
     */
    public function toContactAttributes(): array
    {
        return [
            'name' => $this->name,
            'ico' => $this->ico,
            'dic' => $this->dic,
            'street' => $this->street,
            'city' => $this->city,
            'zip' => $this->zip,
            'country' => $this->country,
        ];
    }

    /**
     * Ploché pole pro cache — DTO se neserializuje přímo, aby změna tvaru
     * třídy neshodila čtení staré cache.
     *
     * @return array<string, mixed>
     */
    public function toCacheArray(): array
    {
        return [
            'ico' => $this->ico,
            'name' => $this->name,
            'dic' => $this->dic,
            'vatPayer' => $this->vatPayer,
            'street' => $this->street,
            'city' => $this->city,
            'zip' => $this->zip,
            'country' => $this->country,
            'textAddress' => $this->textAddress,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromCacheArray(array $data): self
    {
        return new self(
            ico: (string) $data['ico'],
            name: (string) $data['name'],
            dic: $data['dic'] ?? null,
            vatPayer: (bool) ($data['vatPayer'] ?? false),
            street: $data['street'] ?? null,
            city: $data['city'] ?? null,
            zip: $data['zip'] ?? null,
            country: (string) ($data['country'] ?? 'CZ'),
            textAddress: $data['textAddress'] ?? null,
        );
    }

    /**
     * „tř. Václava Klementa 869“, s orientačním číslem „Palackého 358/3“.
     * Subjekty bez pojmenované ulice mají jen číslo popisné: „č.p. 602“.
     *
     * @param  array<string, mixed>  $address
     */
    private static function composeStreet(array $address): ?string
    {
        $houseNumber = $address['cisloDomovni'] ?? null;

        if ($houseNumber === null) {
            return null;
        }

        $number = (string) $houseNumber;

        if (($address['cisloOrientacni'] ?? null) !== null) {
            $number .= '/'.$address['cisloOrientacni'];

            if (($address['cisloOrientacniPismeno'] ?? null) !== null) {
                $number .= $address['cisloOrientacniPismeno'];
            }
        }

        $streetName = self::nullableString($address['nazevUlice'] ?? null);

        if ($streetName !== null) {
            return $streetName.' '.$number;
        }

        // typCisloDomovni: 1 = číslo popisné, 2 = číslo evidenční
        $prefix = ((int) ($address['typCisloDomovni'] ?? 1)) === 2 ? 'č.ev. ' : 'č.p. ';

        return $prefix.$number;
    }

    /**
     * ARES vrací PSČ jako číslo (29301) — český zápis je „293 01“.
     */
    private static function formatZip(mixed $zip): ?string
    {
        if ($zip === null || $zip === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $zip);

        if ($digits === null || strlen($digits) !== 5) {
            return $digits === '' ? null : (string) $digits;
        }

        return substr($digits, 0, 3).' '.substr($digits, 3);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
