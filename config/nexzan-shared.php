<?php

use Nexzan\Shared\Broadcasting\LogEmailHandler;

return [

    'log_notification_email' => env('LOG_NOTIFICATION_EMAIL', 'dev@nexzan.com'),
    'log_mail_channel' => [
        'driver' => 'monolog',
        'level' => 'debug',
        'handler' => LogEmailHandler::class,
    ],

    // Allow user to customize model namespace
    'models' => [
        'team' => \Nexzan\Shared\Models\Team::class,
    ],
    'jobs' => [
        "team_status_update" => \App\Jobs\Team\TeamStatusUpdateJob::class
    ]

];
