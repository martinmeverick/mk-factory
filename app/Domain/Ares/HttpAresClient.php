<?php

declare(strict_types=1);

namespace App\Domain\Ares;

use App\Domain\Contacts\CzechIco;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Volání veřejného REST API ARESu (MF ČR) — bez klíče a registrace.
 *
 * GET  /ekonomicke-subjekty/{ico}      → detail (404 = neexistuje, 400 = špatný formát)
 * POST /ekonomicke-subjekty/vyhledat   → fulltext podle obchodního jména
 */
final class HttpAresClient implements AresClient
{
    public function __construct(
        private readonly bool $enabled,
        private readonly string $baseUri,
        private readonly int $timeout,
        private readonly int $connectTimeout,
    ) {
    }

    public function findByIco(string $ico): ?AresSubject
    {
        $this->assertEnabled();

        $normalized = CzechIco::normalize($ico);

        if ($normalized === null) {
            return null;
        }

        try {
            $response = $this->request()->get($this->baseUri.'/ekonomicke-subjekty/'.$normalized);
        } catch (ConnectionException $e) {
            throw AresUnavailable::because('spojení se nepodařilo navázat', $e);
        }

        // 404 = subjekt neexistuje, 400 = IČO nemůže existovat — obojí je
        // pro volajícího „nenalezeno“, ne chyba registru.
        if (in_array($response->status(), [400, 404], true)) {
            return null;
        }

        if ($response->failed()) {
            throw AresUnavailable::because('registr vrátil stav '.$response->status());
        }

        $data = $response->json();

        if (! is_array($data) || ($data['ico'] ?? null) === null) {
            throw AresUnavailable::because('registr vrátil neočekávanou odpověď');
        }

        return AresSubject::fromRegistryData($data);
    }

    public function searchByName(string $name, int $limit = 10): array
    {
        $this->assertEnabled();

        $name = trim($name);

        if ($name === '') {
            return [];
        }

        try {
            $response = $this->request()->post($this->baseUri.'/ekonomicke-subjekty/vyhledat', [
                'obchodniJmeno' => $name,
                'pocet' => max(1, min($limit, 50)),
                'start' => 0,
            ]);
        } catch (ConnectionException $e) {
            throw AresUnavailable::because('spojení se nepodařilo navázat', $e);
        }

        if ($response->status() === 404) {
            return [];
        }

        if ($response->failed()) {
            throw AresUnavailable::because('registr vrátil stav '.$response->status());
        }

        $subjects = $response->json('ekonomickeSubjekty');

        if (! is_array($subjects)) {
            return [];
        }

        return array_values(array_map(
            static fn (array $subject): AresSubject => AresSubject::fromRegistryData($subject),
            array_filter($subjects, 'is_array'),
        ));
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withHeaders(['User-Agent' => 'MK Factory (invoicing)']);
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled) {
            throw AresUnavailable::disabled();
        }
    }
}
