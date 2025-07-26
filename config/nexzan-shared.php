<?php

use Nexzan\Shared\Broadcasting\LogEmailHandler;

return [

    'log_notification_email' => env('LOG_NOTIFICATION_EMAIL', 'dev@nexzan.com'),
    'log_mail_channel' => [
        'driver' => 'monolog',
        'level' => 'debug',
        'handler' => LogEmailHandler::class,
    ],

    'telescope_prune_threshold' => env('TELSECOPE_PRUNE_THRESHOLD', 24),

    // Allow user to customize model namespace
    'models' => [
        'team' => \Nexzan\Shared\Models\SharedDb\Team::class,
    ],
    'jobs' => [
        "team_status_update" => \App\Jobs\Team\TeamStatusUpdateJob::class
    ]

];
