<?php

use Nexzan\Shared\Broadcasting\LogEmailHandler;

return [

    'log_notification_email'  => env('LOG_NOTIFICATION_EMAIL', 'dev@nexzan.com'),
    'log_mail_channel'        => [
        'driver'  => 'monolog',
        'level'   => 'debug',
        'handler' => LogEmailHandler::class,
    ],

    // AUTH
    'require_api_credentials' => env('require_api_credentials', true),
    'enable_ip_whitelist'     => env('enable_ip_whitelist', false),

    'secret_pepper'           => env('secret_pepper', 'nexzan'),

    // Allow user to customize ApiKey model namespace
    'models'                  => [
        'api_key' => \App\Models\Admin\ApiKey::class,
    ],

    'jwt_secret'              => env('JWT_SECRET', 'WB1CqjG2k9rhtdhBUTK5NhjLqfFcmOkGh3XEvXAGqiE=yN5ZrzPKaQLG6LNS0APsGKkpXxTZard3zMTVGdvVZbC4p6GEyT2Bmr7az5HEWniRiP2O1TTn3WEunKg'),
    'require_jwt_validations' => env("REQUIRE_JWT_VALIDATIONS", true),

];
