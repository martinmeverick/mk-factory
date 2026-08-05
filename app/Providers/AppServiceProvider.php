<?php

namespace App\Providers;

use App\Domain\Ares\AresClient;
use App\Domain\Ares\CachedAresClient;
use App\Domain\Ares\HttpAresClient;
use App\Domain\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Cache;
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
        //
    }
}
