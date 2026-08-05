<?php

declare(strict_types=1);

namespace App\Domain\Contacts;

use App\Enums\ContactType;
use App\Models\Contact;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Dohledá nebo založí odběratele pro automatické fakturování z napojeného
 * systému (U Jabka a další). Na rozdíl od dodavatelů jde převážně o B2C —
 * fyzické osoby BEZ IČO, takže IČO nesmí být párovacím klíčem.
 *
 * Pořadí párování:
 *   1. external_id — jediný spolehlivý klíč, unikátní v rámci organizace,
 *   2. IČO — jen když ho zákazník má (firemní objednávka),
 *   3. e-mail — poslední záchrana pro objednávky bez external_id,
 *   4. založení nového kontaktu.
 *
 * E-mail se schválně NEVYNUCUJE jako unikátní: jednu adresu může sdílet víc
 * osob (domácnost, firma) a tvrdá unikátnost by legitimní objednávky rozbila.
 */
final class CustomerResolver
{
    /**
     * @param  array{name: string, external_id?: ?string, ico?: ?string, dic?: ?string, email?: ?string, phone?: ?string, street?: ?string, city?: ?string, zip?: ?string, country?: ?string}  $data
     */
    public function resolve(array $data): Contact
    {
        $externalId = $this->clean($data['external_id'] ?? null);
        $ico = CzechIco::normalize($data['ico'] ?? null);
        $email = $this->clean($data['email'] ?? null);

        $existing = $this->findExisting($externalId, $ico, $email);

        if ($existing !== null) {
            return $this->ensureUsableAsCustomer($existing, $externalId);
        }

        $attributes = [
            'type' => ContactType::Customer,
            'name' => trim($data['name']),
            'external_id' => $externalId,
            'ico' => $ico,
            'dic' => $this->clean($data['dic'] ?? null),
            'email' => $email,
            'phone' => $this->clean($data['phone'] ?? null),
            'street' => $this->clean($data['street'] ?? null),
            'city' => $this->clean($data['city'] ?? null),
            'zip' => $this->clean($data['zip'] ?? null),
            'country' => $this->clean($data['country'] ?? null) ?? 'CZ',
        ];

        try {
            return Contact::create($attributes);
        } catch (UniqueConstraintViolationException $e) {
            // Souběžné objednávky téhož zákazníka — vyhrál druhý zápis.
            $contact = $this->findExisting($externalId, $ico, $email);

            if ($contact === null) {
                throw $e;
            }

            return $this->ensureUsableAsCustomer($contact, $externalId);
        }
    }

    private function findExisting(?string $externalId, ?string $ico, ?string $email): ?Contact
    {
        if ($externalId !== null) {
            $byExternalId = Contact::query()->where('external_id', $externalId)->first();

            if ($byExternalId !== null) {
                return $byExternalId;
            }
        }

        if ($ico !== null) {
            $byIco = Contact::query()->where('ico', $ico)->first();

            if ($byIco !== null) {
                return $byIco;
            }
        }

        if ($email !== null) {
            return Contact::query()->where('email', $email)->first();
        }

        return null;
    }

    /**
     * Dodavatele použitého nově i jako odběratel povýší na „obojí“ a doplní
     * chybějící external_id, aby další objednávky párovaly na první klíč.
     */
    private function ensureUsableAsCustomer(Contact $contact, ?string $externalId): Contact
    {
        $changes = [];

        if ($contact->type === ContactType::Supplier) {
            $changes['type'] = ContactType::Both;
        }

        if ($externalId !== null && $contact->external_id === null) {
            $changes['external_id'] = $externalId;
        }

        if ($changes !== []) {
            $contact->update($changes);
        }

        return $contact;
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
