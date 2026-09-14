<?php

namespace App\Providers;

use App\Domain\Ares\AresClient;
use App\Domain\Ares\CachedAresClient;
use App\Domain\Ares\HttpAresClient;
use App\Domain\Tenancy\CurrentOrganization;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentOrganization::class);

        $this->app->singleton(AresClient::class, function (): AresClient {
            $config = config('services.ares');

            return new CachedAresClient(
                inner: new HttpAresClient(
                    enabled: (bool) $config['enabled'],
                    baseUri: rtrim((string) $config['base_uri'], '/'),
                    timeout: (int) $config['timeout'],
                    connectTimeout: (int) $config['connect_timeout'],
                ),
                cache: Cache::store(),
                ttl: (int) $config['cache_ttl'],
                missingTtl: (int) $config['missing_cache_ttl'],
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureLoginRateLimiting();
    }

    /**
     * Limity pro POST /login (route middleware `throttle:login`).
     *
     * Dva nezávislé, konečné kbelíky:
     *  - 5 pokusů/min na dvojici (normalizovaný e-mail, IP) — brzdí hádání
     *    hesla k jednomu účtu z jednoho zdroje,
     *  - 30 pokusů/min na IP napříč e-maily — brzdí sprej přes mnoho účtů.
     *
     * Klíč nikdy neobsahuje e-mail v čitelné podobě (hash) a nikdy není jen
     * e-mail bez IP: cizí zdroj tedy nemůže „zamknout“ účet ostatním.
     * Počítá se KAŽDÝ POST, úspěšný i neúspěšný. GET stránka limit nemá.
     *
     * Normalizace (trim + lowercase) slouží jen klíči limiteru; vlastní
     * ověření přihlašovacích údajů v AuthController se nemění.
     *
     * IP je REMOTE_ADDR: nasazení je nginx → PHP-FPM bez další proxy
     * a bootstrap/app.php žádné trustProxies nenastavuje, takže se
     * X-Forwarded-For ignoruje. Stav limiteru žije ve výchozí cache —
     * v produkci musí být sdílená mezi FPM workery (viz docs/VPS_READINESS.md).
     */
    private function configureLoginRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $ip = (string) ($request->ip() ?? '');

            // Vstup může být pole, null nebo cokoli — nesmí to shodit limiter.
            $rawEmail = $request->input('email');
            $identity = is_string($rawEmail) ? mb_strtolower(trim($rawEmail)) : '';

            $tooManyAttempts = static fn (Request $request, array $headers) => response(
                'Příliš mnoho pokusů o přihlášení. Zkuste to prosím znovu za chvíli.',
                429,
                $headers + ['Content-Type' => 'text/plain; charset=UTF-8'],
            );

            return [
                Limit::perMinute(5)
                    ->by('login-identity:'.hash('sha256', $identity.'|'.$ip))
                    ->response($tooManyAttempts),
                Limit::perMinute(30)
                    ->by('login-ip:'.hash('sha256', $ip))
                    ->response($tooManyAttempts),
            ];
        });
    }
}
