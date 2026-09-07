<?php

namespace Nexzan\Shared\Console\Commands;

use Illuminate\Console\Command;
use Nexzan\Shared\Infrastructure\RabbitMQService;

class RabbitDlqRetryCommand extends Command
{
    protected $signature = 'rabbitmq:dlq:retry
        {exchange}
        {queue}
        {--limit=100}
        {--discard-invalid : Permanently discard invalid envelopes from the DLQ}';

    protected $description = 'Republish inspected RabbitMQ dead letters with publisher confirmation';

    public function handle(RabbitMQService $rabbitMQ): int
    {
        $result = $rabbitMQ->retryDeadLetters(
            (string) $this->argument('exchange'),
            (string) $this->argument('queue'),
            max(1, (int) $this->option('limit')),
            (bool) $this->option('discard-invalid'),
        );

        $this->info("Retried {$result['retried']} message(s); discarded {$result['discarded']} invalid message(s).");

        return self::SUCCESS;
    }
}
