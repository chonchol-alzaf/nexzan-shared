<?php

namespace Nexzan\Shared\Console\Commands;

use Illuminate\Console\Command;
use Nexzan\Shared\Enums\OutboxStatus;
use Nexzan\Shared\Models\OutboxEvent;

class OutboxRecoverCommand extends Command
{
    protected $signature = 'outbox:recover';

    protected $description = 'Recover Outbox events left in a stale publishing lease';

    public function handle(): int
    {
        $maximum = (int) config('rabbitmq.outbox_max_attempts', 10);
        $staleBefore = now()->subMinutes((int) config('rabbitmq.outbox_stale_minutes', 5));

        $dead = OutboxEvent::query()
            ->where('status', OutboxStatus::Publishing->value)
            ->where('publishing_started_at', '<=', $staleBefore)
            ->where('attempts', '>=', $maximum)
            ->update([
                'status' => OutboxStatus::Dead->value,
                'publishing_started_at' => null,
                'next_attempt_at' => null,
                'last_error' => 'Recovered stale publishing lease after maximum attempts.',
            ]);

        $failed = OutboxEvent::query()
            ->where('status', OutboxStatus::Publishing->value)
            ->where('publishing_started_at', '<=', $staleBefore)
            ->where('attempts', '<', $maximum)
            ->update([
                'status' => OutboxStatus::Failed->value,
                'publishing_started_at' => null,
                'next_attempt_at' => now(),
                'last_error' => 'Recovered stale publishing lease; publish outcome was unknown.',
            ]);

        $this->info("Recovered {$failed} event(s); marked {$dead} dead.");

        return self::SUCCESS;
    }
}
