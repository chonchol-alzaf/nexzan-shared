<?php

namespace Nexzan\Shared\Console\Commands;

use Illuminate\Console\Command;
use Nexzan\Shared\Infrastructure\DurableOperationProcessor;

class OperationsWorkCommand extends Command
{
    protected $signature = 'operations:work {--once} {--sleep=3}';

    protected $description = 'Execute committed Inbox side effects from their durable ledger';

    public function handle(DurableOperationProcessor $processor): int
    {
        do {
            $worked = $processor->processNext();
            if (! $worked && ! $this->option('once')) {
                sleep(max(1, (int) $this->option('sleep')));
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
