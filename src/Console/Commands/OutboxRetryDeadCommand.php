<?php

namespace Nexzan\Shared\Console\Commands;

use Illuminate\Console\Command;
use Nexzan\Shared\Enums\OutboxStatus;
use Nexzan\Shared\Models\OutboxEvent;

class OutboxRetryDeadCommand extends Command
{
    protected $signature = 'outbox:retry-dead {event_id}';

    protected $description = 'Reset one inspected dead Outbox event for retry';

    public function handle(): int
    {
        $updated = OutboxEvent::query()
            ->where('event_id', $this->argument('event_id'))
            ->where('status', OutboxStatus::Dead->value)
            ->update([
                'status' => OutboxStatus::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => null,
                'publishing_started_at' => null,
                'last_error' => null,
            ]);

        if ($updated !== 1) {
            $this->error('A matching dead Outbox event was not found.');

            return self::FAILURE;
        }

        $this->info('Dead Outbox event reset for retry.');

        return self::SUCCESS;
    }
}
