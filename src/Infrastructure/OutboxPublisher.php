<?php

namespace Nexzan\Shared\Infrastructure;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Nexzan\Shared\Enums\OutboxStatus;
use Nexzan\Shared\Messaging\DomainEventEnvelope;
use Nexzan\Shared\Models\OutboxEvent;
use Throwable;

class OutboxPublisher
{
    public function __construct(private readonly RabbitMQService $rabbitMQ) {}

    public function publishBatch(int $batch = 100): int
    {
        $this->markExhaustedEventsDead();
        $events = $this->claim(max(1, $batch));

        foreach ($events as $event) {
            $this->publish($event);
        }

        return $events->count();
    }

    /** @return Collection<int, OutboxEvent> */
    private function claim(int $batch): Collection
    {
        return DB::transaction(function () use ($batch): Collection {
            $events = OutboxEvent::query()
                ->where(function ($query): void {
                    $query->where('status', OutboxStatus::Pending->value)
                        ->orWhere(function ($query): void {
                            $query->where('status', OutboxStatus::Failed->value)
                                ->where(function ($query): void {
                                    $query->whereNull('next_attempt_at')
                                        ->orWhere('next_attempt_at', '<=', now());
                                });
                        });
                })
                ->where('attempts', '<', (int) config('rabbitmq.outbox_max_attempts', 10))
                ->oldest('created_at')
                ->limit($batch)
                ->lockForUpdate()
                ->get();

            foreach ($events as $event) {
                $event->forceFill([
                    'status' => OutboxStatus::Publishing,
                    'attempts' => $event->attempts + 1,
                    'publishing_started_at' => now(),
                    'next_attempt_at' => null,
                ])->save();
            }

            return $events;
        }, 3);
    }

    private function publish(OutboxEvent $event): void
    {
        try {
            $this->rabbitMQ->publishEnvelope(new DomainEventEnvelope(
                event: $event->event_type,
                eventId: $event->event_id,
                version: 1,
                resource: $event->payload,
                occurredAt: $event->created_at->toIso8601String(),
                producer: $event->producer,
                aggregateType: $event->aggregate_type,
                aggregateId: $event->aggregate_id,
                aggregateVersion: $event->aggregate_version,
            ), $event->exchange, $event->routing_key);

            OutboxEvent::query()->whereKey($event->getKey())->update([
                'status' => OutboxStatus::Published->value,
                'published_at' => now(),
                'publishing_started_at' => null,
                'next_attempt_at' => null,
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $maximum = (int) config('rabbitmq.outbox_max_attempts', 10);
            $dead = $event->attempts >= $maximum;
            OutboxEvent::query()->whereKey($event->getKey())->update([
                'status' => $dead ? OutboxStatus::Dead->value : OutboxStatus::Failed->value,
                'next_attempt_at' => $dead
                    ? null
                    : now()->addSeconds(RetryBackoff::seconds('rabbitmq.outbox_backoff', $event->attempts)),
                'publishing_started_at' => null,
                'last_error' => mb_substr($exception->getMessage(), 0, 4000),
            ]);
        }
    }

    private function markExhaustedEventsDead(): void
    {
        OutboxEvent::query()
            ->whereIn('status', [OutboxStatus::Pending->value, OutboxStatus::Failed->value])
            ->where('attempts', '>=', (int) config('rabbitmq.outbox_max_attempts', 10))
            ->update([
                'status' => OutboxStatus::Dead->value,
                'next_attempt_at' => null,
                'publishing_started_at' => null,
            ]);
    }
}
