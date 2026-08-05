<?php

declare(strict_types=1);

namespace Tests\Feature\Ares;

use App\Domain\Ares\AresSubject;
use App\Domain\Ares\AresUnavailable;
use App\Domain\Ares\CachedAresClient;
use App\Domain\Ares\HttpAresClient;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Chování ARES klienta proti podvrženým odpovědím — testy nikdy nesahají
 * na skutečnou síť (Http::preventStrayRequests v Tests\TestCase).
 */
class AresClientTest extends TestCase
{
    private const BASE = 'https://ares.test/rest';

    private function client(): HttpAresClient
    {
        return new HttpAresClient(enabled: true, baseUri: self::BASE, timeout: 5, connectTimeout: 3);
    }

    private function subjectPayload(): array
    {
        return [
            'ico' => '00177041',
            'obchodniJmeno' => 'Škoda Auto a.s.',
            'dic' => 'CZ00177041',
            'sidlo' => [
                'kodStatu' => 'CZ', 'nazevObce' => 'Mladá Boleslav',
                'nazevUlice' => 'tř. Václava Klementa', 'cisloDomovni' => 869, 'psc' => 29301,
            ],
            'seznamRegistraci' => ['stavZdrojeDph' => 'AKTIVNI'],
        ];
    }

    public function test_returns_subject_for_existing_ico(): void
    {
        Http::fake([self::BASE.'/ekonomicke-subjekty/00177041' => Http::response($this->subjectPayload())]);

        $subject = $this->client()->findByIco('00177041');

        $this->assertInstanceOf(AresSubject::class, $subject);
        $this->assertSame('Škoda Auto a.s.', $subject->name);
    }

    public function test_pads_ico_before_calling_registry(): void
    {
        Http::fake([self::BASE.'/ekonomicke-subjekty/00177041' => Http::response($this->subjectPayload())]);

        $this->client()->findByIco('177041');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ekonomicke-subjekty/00177041'));
    }

    public function test_returns_null_when_subject_does_not_exist(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'nenalezeno'], 404)]);

        $this->assertNull($this->client()->findByIco('00177041'));
    }

    public function test_treats_bad_request_as_not_found(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'chybný formát'], 400)]);

        $this->assertNull($this->client()->findByIco('00177041'));
    }

    public function test_throws_when_registry_returns_server_error(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('', 503)]);

        $this->expectException(AresUnavailable::class);

        $this->client()->findByIco('00177041');
    }

    public function test_throws_when_connection_fails(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(AresUnavailable::class);

        $this->client()->findByIco('00177041');
    }

    public function test_throws_when_response_is_not_a_subject(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['neco' => 'jineho'])]);

        $this->expectException(AresUnavailable::class);

        $this->client()->findByIco('00177041');
    }

    public function test_disabled_integration_reports_unavailable(): void
    {
        $client = new HttpAresClient(enabled: false, baseUri: self::BASE, timeout: 5, connectTimeout: 3);

        $this->expectException(AresUnavailable::class);

        $client->findByIco('00177041');
    }

    public function test_invalid_ico_never_reaches_the_registry(): void
    {
        Http::fake();

        $this->assertNull($this->client()->findByIco('abc'));

        Http::assertNothingSent();
    }

    public function test_search_returns_mapped_subjects(): void
    {
        Http::fake([self::BASE.'/ekonomicke-subjekty/vyhledat' => Http::response([
            'pocetCelkem' => 2,
            'ekonomickeSubjekty' => [
                ['ico' => '04846761', 'obchodniJmeno' => 'Táborská moštárna s.r.o.', 'sidlo' => ['nazevObce' => 'Tábor']],
                ['ico' => '07421249', 'obchodniJmeno' => 'Moštárna Újezd s.r.o.', 'sidlo' => ['nazevObce' => 'Chanovice']],
            ],
        ])]);

        $results = $this->client()->searchByName('Moštárna');

        $this->assertCount(2, $results);
        $this->assertSame('Táborská moštárna s.r.o.', $results[0]->name);
    }

    public function test_search_with_empty_query_does_not_call_registry(): void
    {
        Http::fake();

        $this->assertSame([], $this->client()->searchByName('   '));

        Http::assertNothingSent();
    }

    public function test_cache_prevents_repeated_lookups(): void
    {
        Http::fake([self::BASE.'/*' => Http::response($this->subjectPayload())]);

        $cached = new CachedAresClient(
            inner: $this->client(),
            cache: new Repository(new ArrayStore),
            ttl: 3600,
            missingTtl: 600,
        );

        $first = $cached->findByIco('00177041');
        $second = $cached->findByIco('00177041');

        $this->assertEquals($first, $second);
        Http::assertSentCount(1);
    }

    public function test_cache_also_remembers_missing_subjects(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('', 404)]);

        $cached = new CachedAresClient(
            inner: $this->client(),
            cache: new Repository(new ArrayStore),
            ttl: 3600,
            missingTtl: 600,
        );

        $this->assertNull($cached->findByIco('00177041'));
        $this->assertNull($cached->findByIco('00177041'));

        Http::assertSentCount(1);
    }
}
