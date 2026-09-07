<?php

namespace Nexzan\Shared\Infrastructure;

use Nexzan\Shared\Enums\InboxStatus;
use Nexzan\Shared\Models\InboxEvent;
use RuntimeException;

class InboxEventDispatcher
{
    public function dispatch(InboxEvent $inboxEvent): void
    {
        $inboxEvent->refresh();

        if (in_array($inboxEvent->status, [
            InboxStatus::Queued,
            InboxStatus::Processing,
            InboxStatus::Completed,
            InboxStatus::Dead,
        ], true)) {
            return;
        }

        $jobClass = (string) config('rabbitmq.inbox_job');

        if ($jobClass === '' || ! class_exists($jobClass)) {
            throw new RuntimeException("Configured RabbitMQ Inbox job does not exist: {$jobClass}");
        }

        $jobClass::dispatch((string) $inboxEvent->getKey())->onQueue($inboxEvent->queue_name);

        InboxEvent::query()
            ->whereKey($inboxEvent->getKey())
            ->whereIn('status', [InboxStatus::Pending->value, InboxStatus::Failed->value])
            ->update([
                'status' => InboxStatus::Queued->value,
                'dispatched_at' => now(),
                'available_at' => null,
            ]);
    }
}
