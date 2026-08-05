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
 * Pořadí párování je striktní — slabší klíč NIKDY nezachraňuje neúspěch
 * silnějšího:
 *   1. external_id — je-li dodán, rozhoduje výhradně on,
 *   2. IČO — jen bez external_id,
 *   3. e-mail — jen bez external_id i IČA a jen při právě jedné shodě,
 *   4. jinak se založí nový kontakt.
 *
 * E-mail se schválně NEVYNUCUJE jako unikátní: jednu adresu může sdílet víc
 * osob (domácnost, firma) a tvrdá unikátnost by legitimní objednávky rozbila.
 * Při více shodách se proto vyhodí AmbiguousCustomerMatch — přiřadit fakturu
 * náhodné osobě podle pořadí v databázi je nepřípustné.
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
            return $this->ensureUsableAsCustomer($existing);
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

            if ($contact !== null) {
                return $this->ensureUsableAsCustomer($contact);
            }

            // Nové external_id, ale IČO už drží jiný kontakt: identity
            // nesloučíme odhadem, jen srozumitelně nahlásíme konflikt.
            if ($externalId !== null && $ico !== null) {
                throw AmbiguousCustomerMatch::forConflictingIco($ico, $externalId);
            }

            throw $e;
        }
    }

    /**
     * Párovací pořadí je striktní a NEPADÁ zpět na slabší klíč:
     *
     * 1. external_id — je-li dodán, hledá se VÝHRADNĚ podle něj. Když nic
     *    nenajde, zakládá se nový kontakt; „záchrana“ e-mailem ani IČEM by
     *    novou identitu chybně slepila se starým kontaktem.
     * 2. IČO — jen bez external_id.
     * 3. e-mail — jen bez external_id i bez použitelného IČO, a jen když
     *    odpovídá právě jednomu kontaktu.
     *
     * Všechny dotazy jdou přes tenant-scoped Contact::query().
     *
     * @throws AmbiguousCustomerMatch
     */
    private function findExisting(?string $externalId, ?string $ico, ?string $email): ?Contact
    {
        if ($externalId !== null) {
            return Contact::query()->where('external_id', $externalId)->first();
        }

        if ($ico !== null) {
            return Contact::query()->where('ico', $ico)->first();
        }

        if ($email !== null) {
            $matches = Contact::query()->where('email', $email)->limit(2)->get();

            if ($matches->count() > 1) {
                throw AmbiguousCustomerMatch::forEmail(
                    $email,
                    Contact::query()->where('email', $email)->count(),
                );
            }

            return $matches->first();
        }

        return null;
    }

    /**
     * Dodavatele použitého nově i jako odběratel povýší na „obojí“.
     *
     * external_id se zde záměrně NEDOPLŇUJE: shoda podle e-mailu nebo IČA
     * nastane jen tehdy, když volající external_id vůbec nedodal, a shoda
     * podle external_id ho už mít musí. Dopisovat cizí klíč na kontakt
     * nalezený slabším klíčem by tiše slepilo dvě různé identity.
     */
    private function ensureUsableAsCustomer(Contact $contact): Contact
    {
        if ($contact->type === ContactType::Supplier) {
            $contact->update(['type' => ContactType::Both]);
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
