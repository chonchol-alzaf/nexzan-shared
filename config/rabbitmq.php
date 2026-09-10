<?php

return [
    'retained_resources' => [],
    'resource_messages' => [],
    // Only replaceable snapshots may discard older versions, never commands/membership deltas.
    'snapshot_events' => [
        'project.created', 'project.updated', 'site.ready', 'site.update', 'server.status_update',
        'team.account_status.updated', 'team.billing_status.updated', 'team.grace_period.updated',
    ],
    // Use the existing update stream keys so previously consumed versions survive
    // the upgrade. Other snapshot types (including each team field) stay separate.
    'snapshot_event_groups' => [
        'site.ready' => 'site.update',
        'site.update' => 'site.update',
        'project.created' => 'project.updated',
        'project.updated' => 'project.updated',
    ],
    'producer' => env('RABBITMQ_PRODUCER', env('APP_NAME', 'nexzan-service')),
    'host' => env('RABBITMQ_HOST', '127.0.0.1'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),
    'connection_timeout' => (float) env('RABBITMQ_CONNECTION_TIMEOUT', 3),
    'read_write_timeout' => (float) env('RABBITMQ_READ_WRITE_TIMEOUT', 65),
    'heartbeat' => (int) env('RABBITMQ_HEARTBEAT', 30),
    'channel_rpc_timeout' => (float) env('RABBITMQ_CHANNEL_RPC_TIMEOUT', 5),
    'prefetch_count' => (int) env('RABBITMQ_PREFETCH_COUNT', 10),
    'publisher_confirm_timeout' => (float) env('RABBITMQ_PUBLISHER_CONFIRM_TIMEOUT', 5),
    'enable_dlx' => filter_var(env('RABBITMQ_ENABLE_DLX', true), FILTER_VALIDATE_BOOL),
    'declare_only' => false,
    'outbox_max_attempts' => (int) env('RABBITMQ_OUTBOX_MAX_ATTEMPTS', 10),
    'inbox_max_attempts' => (int) env('RABBITMQ_INBOX_MAX_ATTEMPTS', 10),
    'outbox_stale_minutes' => (int) env('RABBITMQ_OUTBOX_STALE_MINUTES', 5),
    'inbox_stale_minutes' => (int) env('RABBITMQ_INBOX_STALE_MINUTES', 5),
    'inbox_dependency_backoff' => (int) env('RABBITMQ_INBOX_DEPENDENCY_BACKOFF', 30),
    'outbox_backoff' => [10, 30, 60, 120, 300, 600],
    'inbox_backoff' => [10, 30, 60, 120, 300, 600],
    'inbox_job' => env('RABBITMQ_INBOX_JOB', 'App\\Jobs\\RabbitMessageHandleJob'),
];
