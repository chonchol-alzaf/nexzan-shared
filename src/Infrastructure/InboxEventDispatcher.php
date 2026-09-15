<?php

namespace Nexzan\Shared\Infrastructure;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nexzan\Shared\Enums\InboxStatus;
use Nexzan\Shared\Models\InboxEvent;
use RuntimeException;
use Throwable;

class InboxEventDispatcher
{
    public function dispatch(InboxEvent $inboxEvent, bool $recoverStale = false): bool
    {
        $jobClass = (string) config('rabbitmq.inbox_job');
        if ($jobClass === '' || ! class_exists($jobClass)) {
            throw new RuntimeException("Configured RabbitMQ Inbox job does not exist: {$jobClass}");
        }

        $claim = DB::transaction(function () use ($inboxEvent, $recoverStale): ?InboxEvent {
            $event = InboxEvent::query()->lockForUpdate()->find($inboxEvent->getKey());
            if (! $event || ! $this->eligible($event, $recoverStale)) {
                return null;
            }
            $event->forceFill([
                'status' => InboxStatus::Queued,
                'dispatch_token' => (string) Str::uuid(),
                'dispatched_at' => now(),
                'available_at' => null,
                'processing_started_at' => null,
            ])->save();

            return $event;
        }, 3);
        if (! $claim) {
            return false;
        }

        // Also honors a caller's outer transaction. No worker can see an uncommitted claim.
        DB::afterCommit(function () use ($claim, $jobClass): void {
            try {
                app(Dispatcher::class)->dispatch((new $jobClass((string) $claim->id, $claim->dispatch_token))->onQueue($claim->queue_name));
            } catch (Throwable $exception) {
                InboxEvent::query()->whereKey($claim->id)
                    ->where('dispatch_token', $claim->dispatch_token)
                    ->where('status', InboxStatus::Queued->value)
                    ->update([
                        'status' => InboxStatus::Failed->value,
                        'available_at' => now()->addSeconds(RetryBackoff::seconds('rabbitmq.inbox_backoff', 1)),
                        'last_error' => mb_substr($exception->getMessage(), 0, 4000),
                    ]);
                throw $exception;
            }
        });

        return true;
    }

    private function eligible(InboxEvent $event, bool $recoverStale): bool
    {
        if ($event->attempts >= (int) config('rabbitmq.inbox_max_attempts', 10)) {
            return false;
        }
        if ($event->status === InboxStatus::Pending) {
            return true;
        }
        if (in_array($event->status, [InboxStatus::Failed, InboxStatus::Waiting], true)) {
            return ! $event->available_at?->isFuture();
        }
        if (! $recoverStale) {
            return false;
        }
        $staleBefore = now()->subMinutes((int) config('rabbitmq.inbox_stale_minutes', 5));

        return match ($event->status) {
            InboxStatus::Queued => $event->dispatched_at !== null && $event->dispatched_at->lte($staleBefore),
            InboxStatus::Processing => $event->processing_started_at !== null && $event->processing_started_at->lte($staleBefore),
            default => false,
        };
    }
}
