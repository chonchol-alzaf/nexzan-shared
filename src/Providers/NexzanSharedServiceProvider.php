<?php

namespace Nexzan\Shared\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Nexzan\Shared\Console\Commands\InboxRecoverCommand;
use Nexzan\Shared\Console\Commands\InboxRetryDeadCommand;
use Nexzan\Shared\Console\Commands\Migration;
use Nexzan\Shared\Console\Commands\MigrationFresh;
use Nexzan\Shared\Console\Commands\MigrationRollback;
use Nexzan\Shared\Console\Commands\OutboxRecoverCommand;
use Nexzan\Shared\Console\Commands\OutboxRetryDeadCommand;
use Nexzan\Shared\Console\Commands\OutboxWorkCommand;
use Nexzan\Shared\Console\Commands\RabbitDlqRetryCommand;
use Nexzan\Shared\Supports\AuthHelper;

class NexzanSharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/nexzan-shared.php', 'nexzan-shared');
        $this->mergeConfigFrom(__DIR__.'/../../config/rabbitmq.php', 'rabbitmq');

        $this->app->singleton('authUser', function () {
            return new AuthHelper;
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $channels = config('logging.channels');
        if (! array_key_exists('mail', $channels)) {
            $channels['mail'] = config('nexzan-shared.log_mail_channel');

            config(['logging.channels' => $channels]);
        }

        // Load views with namespace
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'nexzan-shared');

        // Allow views to be published to app
        $this->publishes([
            __DIR__.'/../../resources/views' => resource_path('views/vendor/nexzan-shared'),
        ], 'nexzan-shared-views');

        $this->publishes([
            __DIR__.'/../../config/nexzan-shared.php' => config_path('nexzan-shared.php'),
        ], 'nexzan-shared-config');

        if ($this->app->runningInConsole()) {
            // Register commands
            $this->commands([
                Migration::class,
                MigrationRollback::class,
                MigrationFresh::class,
                OutboxWorkCommand::class,
                OutboxRecoverCommand::class,
                InboxRecoverCommand::class,
                OutboxRetryDeadCommand::class,
                InboxRetryDeadCommand::class,
                RabbitDlqRetryCommand::class,
            ]);

            // Register schedules
            $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);

                $schedule->command('telescope:prune --hours='.config('nexzan-shared.telescope_prune_threshold', 24))
                    ->daily();
            });
        }
    }
}
