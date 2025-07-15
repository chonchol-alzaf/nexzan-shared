<?php

return [

    'log_notification_email' => env('LOG_NOTIFICATION_EMAIL', 'dev@nexzan.com'),

     // Allow user to customize model namespace
    'models' => [
        'team' => \Nexzan\Shared\Models\Team::class,
    ],

];