<?php
namespace Nexzan\Shared\Providers;

use Illuminate\Support\ServiceProvider;
use Nexzan\Shared\Supports\AuthHelper;

class NexzanSharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/nexzan-shared.php', 'nexzan-shared');

        $this->app->singleton('authUser', function () {
            return new AuthHelper();
        });
    }

    public function boot(): void
    {
        $channels = config('logging.channels');
        if (! array_key_exists('mail', $channels)) {
            $channels['mail'] = config('nexzan-shared.log_mail_channel');

            config(['logging.channels' => $channels]);
        }

        // Load views with namespace
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'nexzan-shared');

        // Allow views to be published to app
        $this->publishes([
            __DIR__ . '/../../resources/views' => resource_path('views/vendor/nexzan-shared'),
        ], 'nexzan-shared-views');

        $this->publishes([
            __DIR__ . '/../../config/nexzan-shared.php' => config_path('nexzan-shared.php'),
        ], 'nexzan-shared-config');

        if ($this->app->runningInConsole()) {
            // Register commands
            $this->commands([
                \Nexzan\Shared\Console\Commands\Migration::class,
                \Nexzan\Shared\Console\Commands\MigrationRollback::class,
                \Nexzan\Shared\Console\Commands\MigrationFresh::class,
            ]);

            // Register schedules
            $this->app->booted(function () {
                $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

                $schedule->command('telescope:prune --hours=' . config('nexzan-shared.telescope_prune_threshold', 24))
                    ->daily();
            });
        }
    }
}
