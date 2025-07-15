<?php

namespace Nexzan\Shared\Providers;

use Illuminate\Support\ServiceProvider;

class NexzanSharedServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Load views with namespace
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'nexzan-shared');

        // Allow views to be published to app
        $this->publishes([
            __DIR__.'/../../resources/views' => resource_path('views/vendor/nexzan-shared'),
        ], 'views');
    }

    public function register(): void
    {
        //
    }
}
