<?php

namespace Nexzan\Shared\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class NexzanSharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/nexzan-shared.php', 'nexzan-shared');
    }

    public function boot(): void
    {
        // Load views with namespace
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'nexzan-shared');

        // Allow views to be published to app
        $this->publishes([
            __DIR__ . '/../../resources/views' => resource_path('views/vendor/nexzan-shared'),
        ], 'nexzan-shared-views');

        $this->publishes([
            __DIR__ . '/../../config/nexzan-shared.php' => config_path('nexzan-shared.php'),
        ], 'nexzan-shared-config');

        Route::middleware(['api'])
            ->prefix('v1/internal')
            ->name('v1.internal.')
            ->group(__DIR__ . '/../../routes/v1/micro-service/api.php');
    }
}
