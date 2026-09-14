<?php

declare(strict_types=1);

namespace App\Domain\Contacts;

use App\Domain\Ares\AresClient;
use App\Domain\Ares\AresUnavailable;
use App\Enums\ContactType;
use App\Models\Contact;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Najde dodavatele podle IČO v aktuální organizaci, a pokud ještě neexistuje,
 * založí ho z údajů ARESu. Díky tomu nemusí uživatel zakládat kontakt předem
 * a opakované faktury od stejné firmy nevytvoří duplicity.
 *
 * Hledá se VŽDY nejdřív lokálně — na už známé IČO se ARESu vůbec neptáme.
 */
final class SupplierResolver
{
    public function __construct(
        private readonly AresClient $ares,
    ) {
    }

    /**
     * @param  string|null  $fallbackName  název použitý, když ARES nepomůže
     *
     * @throws SupplierNotResolvable
     */
    public function resolveByIco(string $ico, ?string $fallbackName = null): Contact
    {
        $normalized = CzechIco::normalize($ico);

        if ($normalized === null) {
            throw SupplierNotResolvable::unknownIco($ico);
        }

        $existing = Contact::query()->where('ico', $normalized)->first();

        if ($existing !== null) {
            return $this->ensureUsableAsSupplier($existing);
        }

        $attributes = $this->attributesFromRegistry($normalized, $fallbackName);

        try {
            return Contact::create($attributes + ['type' => ContactType::Supplier]);
        } catch (UniqueConstraintViolationException) {
            // Souběžné uložení dvou faktur se stejným IČO — vyhrál druhý zápis.
            $contact = Contact::query()->where('ico', $normalized)->first();

            if ($contact === null) {
                throw SupplierNotResolvable::unknownIco($normalized);
            }

            return $this->ensureUsableAsSupplier($contact);
        }
    }

    /**
     * Kontakt vedený zatím jen jako odběratel se povýší na „odběratel
     * i dodavatel“ — nevzniká druhý záznam pro stejnou firmu.
     */
    private function ensureUsableAsSupplier(Contact $contact): Contact
    {
        if ($contact->type === ContactType::Customer) {
            $contact->update(['type' => ContactType::Both]);
        }

        return $contact;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SupplierNotResolvable
     */
    private function attributesFromRegistry(string $ico, ?string $fallbackName): array
    {
        $fallbackName = $fallbackName !== null ? trim($fallbackName) : null;
        $fallbackName = $fallbackName === '' ? null : $fallbackName;

        try {
            $subject = $this->ares->findByIco($ico);
        } catch (AresUnavailable) {
            if ($fallbackName === null) {
                throw SupplierNotResolvable::registryUnavailable();
            }

            return ['ico' => $ico, 'name' => $fallbackName];
        }

        if ($subject === null) {
            if ($fallbackName === null) {
                throw SupplierNotResolvable::unknownIco($ico);
            }

            return ['ico' => $ico, 'name' => $fallbackName];
        }

        $attributes = $subject->toContactAttributes();

        // Ručně zadaný název má přednost před registrovým.
        if ($fallbackName !== null) {
            $attributes['name'] = $fallbackName;
        }

        return $attributes;
    }
}
