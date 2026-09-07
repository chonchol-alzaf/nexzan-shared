<?php

namespace Nexzan\Shared\Console\Commands;

use Illuminate\Console\Command;
use Nexzan\Shared\Infrastructure\OutboxPublisher;

class OutboxWorkCommand extends Command
{
    protected $signature = 'outbox:work {--once : Process one batch and exit} {--batch=100} {--sleep=3}';

    protected $description = 'Publish reliable domain events from the transactional Outbox';

    public function handle(OutboxPublisher $publisher): int
    {
        $batch = max(1, (int) $this->option('batch'));
        $sleep = max(1, (int) $this->option('sleep'));

        do {
            $processed = $publisher->publishBatch($batch);

            if ($this->option('once')) {
                $this->info("Processed {$processed} Outbox event(s).");

                return self::SUCCESS;
            }

            if ($processed === 0) {
                sleep($sleep);
            }
        } while (true);
    }
}
