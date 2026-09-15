<?php

namespace Nexzan\Shared\Console\Commands;

use Illuminate\Console\Command;
use Nexzan\Shared\Enums\InboxStatus;
use Nexzan\Shared\Models\InboxEvent;

class InboxRetryDeadCommand extends Command
{
    protected $signature = 'inbox:retry-dead {event_id}';

    protected $description = 'Reset one inspected dead Inbox event for recovery';

    public function handle(): int
    {
        $updated = InboxEvent::query()
            ->where('event_id', $this->argument('event_id'))
            ->where('status', InboxStatus::Dead->value)
            ->update([
                'status' => InboxStatus::Pending->value,
                'attempts' => 0,
                'available_at' => null,
                'dispatched_at' => null,
                'processing_started_at' => null,
                'last_error' => null,
            ]);

        if ($updated !== 1) {
            $this->error('A matching dead Inbox event was not found.');

            return self::FAILURE;
        }

        $this->info('Dead Inbox event reset; inbox:recover will dispatch it.');

        return self::SUCCESS;
    }
}
