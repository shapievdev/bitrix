<?php

namespace App\Providers;

use App\Services\Bitrix24\Bitrix24Manager;
use App\Services\Bitrix24\PortalThrottle;
use App\Services\Bitrix24\TokenManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenManager::class);
        $this->app->singleton(PortalThrottle::class);
        $this->app->singleton(Bitrix24Manager::class);
    }

    public function boot(): void
    {
        // Битрикс грузит приложение только по HTTPS: страница со ссылками
        // на http внутри фрейма будет заблокирована браузером.
        if ($this->app->isProduction() || config('bitrix24.force_https')) {
            URL::forceScheme('https');
        }

        // Обращение к незагруженной связи должно падать на разработке,
        // а не тихо превращаться в N+1 при синхронизации задач.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());
    }
}
