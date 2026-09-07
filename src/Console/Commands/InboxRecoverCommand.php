<?php

namespace Nexzan\Shared\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nexzan\Shared\Enums\InboxStatus;
use Nexzan\Shared\Infrastructure\InboxEventDispatcher;
use Nexzan\Shared\Models\InboxEvent;

class InboxRecoverCommand extends Command
{
    protected $signature = 'inbox:recover {--batch=100}';

    protected $description = 'Redispatch pending, retry-ready, or stale Inbox events';

    public function handle(InboxEventDispatcher $dispatcher): int
    {
        $batch = max(1, (int) $this->option('batch'));
        $maximum = (int) config('rabbitmq.inbox_max_attempts', 10);
        $staleBefore = now()->subMinutes((int) config('rabbitmq.inbox_stale_minutes', 5));

        InboxEvent::query()
            ->where('status', InboxStatus::Failed->value)
            ->where('attempts', '>=', $maximum)
            ->update(['status' => InboxStatus::Dead->value, 'available_at' => null]);

        $ids = InboxEvent::query()
            ->where('attempts', '<', $maximum)
            ->where(function ($query) use ($staleBefore): void {
                $query->where('status', InboxStatus::Pending->value)
                    ->orWhere(function ($query): void {
                        $query->where('status', InboxStatus::Failed->value)
                            ->where(function ($query): void {
                                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
                            });
                    })
                    ->orWhere(function ($query) use ($staleBefore): void {
                        $query->where('status', InboxStatus::Queued->value)
                            ->where('dispatched_at', '<=', $staleBefore);
                    })
                    ->orWhere(function ($query) use ($staleBefore): void {
                        $query->where('status', InboxStatus::Processing->value)
                            ->where('processing_started_at', '<=', $staleBefore);
                    });
            })
            ->oldest('created_at')
            ->limit($batch)
            ->pluck('id');

        $dispatched = 0;

        foreach ($ids as $id) {
            $event = DB::transaction(function () use ($id): ?InboxEvent {
                $event = InboxEvent::query()->lockForUpdate()->find($id);

                if (! $event || in_array($event->status, [InboxStatus::Completed, InboxStatus::Dead], true)) {
                    return null;
                }

                $event->forceFill([
                    'status' => InboxStatus::Pending,
                    'available_at' => null,
                    'dispatched_at' => null,
                    'processing_started_at' => null,
                ])->save();

                return $event;
            }, 3);

            if ($event) {
                $dispatcher->dispatch($event);
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} recoverable Inbox event(s).");

        return self::SUCCESS;
    }
}
