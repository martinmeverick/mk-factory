<?php

declare(strict_types=1);

namespace App\Domain\Ares;

/**
 * Přístup k registru ARES.
 *
 * Rozlišuje dva různé stavy:
 * - subjekt neexistuje → návratová hodnota null / prázdné pole (běžný výsledek),
 * - registr je nedostupný → výjimka AresUnavailable (volající musí umět
 *   pokračovat bez ARESu).
 */
interface AresClient
{
    /**
     * @throws AresUnavailable
     */
    public function findByIco(string $ico): ?AresSubject;

    /**
     * @return list<AresSubject>
     *
     * @throws AresUnavailable
     */
    public function searchByName(string $name, int $limit = 10): array;
}
