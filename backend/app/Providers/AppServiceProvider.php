<?php

namespace App\Providers;

use App\Services\StoreProviders\LentaProvider;
use App\Services\StoreProviders\LentaApiClient;
use App\Services\StoreProviders\MagnitProvider;
use App\Services\StoreProviders\MetroProvider;
use App\Services\StoreProviders\StoreProviderManager;
use App\Services\ParseRunProgress;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ParseRunProgress::class);
        $this->app->singleton(LentaApiClient::class);

        $this->app->singleton(StoreProviderManager::class, function ($app) {
            return new StoreProviderManager([
                $app->make(MagnitProvider::class),
                $app->make(MetroProvider::class),
                $app->make(LentaProvider::class),
            ]);
        });
    }
}
