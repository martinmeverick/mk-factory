<?php

declare(strict_types=1);

namespace App\Domain\Ares;

use App\Domain\Contacts\CzechIco;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Cache nad ARES klientem. Údaje v registru se mění zřídka, takže cache
 * zásadně snižuje počet dotazů na veřejnou službu (a riziko throttlingu).
 *
 * Cachuje se i výsledek „nenalezeno“, aby opakované dotazy na neexistující
 * IČO nechodily ven; kratší TTL kvůli nově vzniklým subjektům.
 *
 * Ukládá se ploché pole, ne DTO — změna tvaru třídy pak neshodí starou cache.
 */
final class CachedAresClient implements AresClient
{
    public function __construct(
        private readonly AresClient $inner,
        private readonly Cache $cache,
        private readonly int $ttl,
        private readonly int $missingTtl,
    ) {
    }

    public function findByIco(string $ico): ?AresSubject
    {
        $normalized = CzechIco::normalize($ico);

        if ($normalized === null) {
            return null;
        }

        $key = 'ares:ico:'.$normalized;
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return ($cached['found'] ?? false)
                ? AresSubject::fromCacheArray($cached['data'])
                : null;
        }

        $subject = $this->inner->findByIco($normalized);

        $this->cache->put(
            $key,
            $subject === null
                ? ['found' => false]
                : ['found' => true, 'data' => $subject->toCacheArray()],
            $subject === null ? $this->missingTtl : $this->ttl,
        );

        return $subject;
    }

    /**
     * Vyhledávání podle názvu se necachuje — je to interaktivní našeptávač
     * s vysokou variabilitou dotazů, cache by měla mizivou úspěšnost.
     */
    public function searchByName(string $name, int $limit = 10): array
    {
        return $this->inner->searchByName($name, $limit);
    }
}
