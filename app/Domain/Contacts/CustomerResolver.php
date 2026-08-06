<?php

declare(strict_types=1);

namespace App\Domain\Contacts;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ContactType;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
 *
 * E-mail NENÍ stabilní identita — je to slabý fallback. Integrace má vždy
 * posílat external_id (viz INTEGRATION_CONTRACT.md). E-mail se před hledáním
 * i uložením kanonizuje (trim + lowercase); žádné provider-specific úpravy
 * (gmail tečky, +tagy, přepis domén) se nedělají, protože by slepily
 * odlišné adresy.
 *
 * Souběh email-only požadavků serializuje tenant-scoped aplikační zámek nad
 * kanonickým e-mailem — bez něj dva souběžné požadavky projdou lookupem
 * naprázdno a založí duplicitní kontakty (unikátní index tu z principu není).
 */
final class CustomerResolver
{
    private const int EMAIL_LOCK_TIMEOUT_SECONDS = 10;

    /**
     * @param  array{name: string, external_id?: ?string, ico?: ?string, dic?: ?string, email?: ?string, phone?: ?string, street?: ?string, city?: ?string, zip?: ?string, country?: ?string}  $data
     */
    public function resolve(array $data): Contact
    {
        $externalId = $this->clean($data['external_id'] ?? null);
        $ico = CzechIco::normalize($data['ico'] ?? null);
        $email = $this->canonicalEmail($data['email'] ?? null);

        // Email-only vstup drží jen slabý klíč — lookup i případné založení
        // se serializují zámkem, jinak souběh vytvoří duplicitní identity.
        // Silnější klíče zámek nepotřebují: kryje je unikátní index
        // (organization_id, external_id) resp. (organization_id, ico)
        // a catch UniqueConstraintViolationException níže.
        if ($externalId === null && $ico === null && $email !== null) {
            return $this->withEmailLock(
                $email,
                fn (): Contact => $this->resolveOnce($externalId, $ico, $email, $data),
            );
        }

        return $this->resolveOnce($externalId, $ico, $email, $data);
    }

    /**
     * Jeden průchod find-or-create. Uvnitř email zámku se volá až PO jeho
     * získání, takže druhý souběžný požadavek najde kontakt založený prvním.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveOnce(?string $externalId, ?string $ico, ?string $email, array $data): Contact
    {
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
            $matches = $this->emailQuery($email)->limit(2)->get();

            if ($matches->count() > 1) {
                throw AmbiguousCustomerMatch::forEmail(
                    $email,
                    $this->emailQuery($email)->count(),
                );
            }

            return $matches->first();
        }

        return null;
    }

    /**
     * Kanonické porovnání e-mailu: LOWER() na straně DB proti kanonické
     * hodnotě. Ručně založený kontakt s 'User@Example.com' se tak najde
     * i pro 'user@example.com' z integrace — shodně na MariaDB (CI kolace)
     * i na SQLite (case-sensitive `=`).
     *
     * @return Builder<Contact>
     */
    private function emailQuery(string $canonicalEmail): Builder
    {
        return Contact::query()->whereRaw('LOWER(email) = ?', [$canonicalEmail]);
    }

    /**
     * Tenant-scoped aplikační zámek nad kanonickým e-mailem (GET_LOCK).
     * Mimo MySQL/MariaDB (hlavní SQLite sada běží v jednom procesu) se
     * zámek neuplatní; skutečný souběh ověřuje MariaDB concurrency sada.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withEmailLock(string $canonicalEmail, \Closure $callback)
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            return $callback();
        }

        // Název zámku: hash (organizace, e-mail) — vejde se do limitu 64
        // znaků (MySQL) a nese tenant scope, takže stejný e-mail v jiné
        // organizaci neblokuje.
        $name = 'mkf:cust:'.substr(
            hash('sha256', (app(CurrentOrganization::class)->id() ?? 'none').'|'.$canonicalEmail),
            0,
            40,
        );

        $acquired = $connection->selectOne(
            'SELECT GET_LOCK(?, ?) AS acquired',
            [$name, self::EMAIL_LOCK_TIMEOUT_SECONDS],
        );

        if ((int) ($acquired->acquired ?? 0) !== 1) {
            throw new RuntimeException(
                'Nepodařilo se získat zámek pro párování zákazníka podle e-mailu — zkuste požadavek opakovat.',
            );
        }

        try {
            return $callback();
        } finally {
            $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
        }
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

    /**
     * Kanonizace e-mailu: trim + lowercase bezpečný pro UTF-8. Nic víc —
     * odstranění teček, +tagů či přepis domén by slepily odlišné adresy.
     */
    private function canonicalEmail(?string $value): ?string
    {
        $cleaned = $this->clean($value);

        return $cleaned === null ? null : mb_strtolower($cleaned, 'UTF-8');
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
