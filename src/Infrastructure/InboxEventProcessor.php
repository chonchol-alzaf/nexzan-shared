<?php

namespace Nexzan\Shared\Infrastructure;

use Illuminate\Support\Facades\DB;
use Nexzan\Shared\Enums\InboxStatus;
use Nexzan\Shared\Models\ConsumedAggregateVersion;
use Nexzan\Shared\Models\InboxEvent;
use Throwable;

class InboxEventProcessor
{
    public function process(string $inboxEventId, callable $handler): void
    {
        $initialTransactionLevel = DB::transactionLevel();

        try {
            DB::transaction(function () use ($inboxEventId, $handler): void {
                /** @var InboxEvent $inbox */
                $inbox = InboxEvent::query()->lockForUpdate()->findOrFail($inboxEventId);

                if (in_array($inbox->status, [InboxStatus::Completed, InboxStatus::Dead], true)) {
                    return;
                }

                $inbox->forceFill([
                    'status' => InboxStatus::Processing,
                    'attempts' => $inbox->attempts + 1,
                    'processing_started_at' => now(),
                    'last_error' => null,
                ])->save();

                if ($this->isStaleAggregateVersion($inbox)) {
                    $this->complete($inbox);

                    return;
                }

                $handler($inbox->payload, $inbox->exchange);
                $this->rememberAggregateVersion($inbox);
                $this->complete($inbox);
            });
        } catch (Throwable $exception) {
            while (DB::transactionLevel() > $initialTransactionLevel) {
                DB::rollBack();
            }

            $this->recordFailure($inboxEventId, $exception);
            throw $exception;
        }
    }

    private function complete(InboxEvent $inbox): void
    {
        $inbox->forceFill([
            'status' => InboxStatus::Completed,
            'processed_at' => now(),
            'available_at' => null,
            'processing_started_at' => null,
            'last_error' => null,
        ])->save();
    }

    private function recordFailure(string $inboxEventId, Throwable $exception): void
    {
        DB::transaction(function () use ($inboxEventId, $exception): void {
            /** @var InboxEvent|null $inbox */
            $inbox = InboxEvent::query()->lockForUpdate()->find($inboxEventId);

            if (! $inbox || in_array($inbox->status, [InboxStatus::Completed, InboxStatus::Dead], true)) {
                return;
            }

            $maximum = (int) config('rabbitmq.inbox_max_attempts', 10);
            $attempts = $inbox->attempts + 1;
            $dead = $attempts >= $maximum;
            $inbox->forceFill([
                'status' => $dead ? InboxStatus::Dead : InboxStatus::Failed,
                'attempts' => $attempts,
                'available_at' => $dead
                    ? null
                    : now()->addSeconds(RetryBackoff::seconds('rabbitmq.inbox_backoff', $attempts)),
                'processing_started_at' => null,
                'last_error' => mb_substr($exception->getMessage(), 0, 4000),
            ])->save();
        }, 3);
    }

    private function isStaleAggregateVersion(InboxEvent $inbox): bool
    {
        if (! $this->hasOrderedAggregate($inbox)) {
            return false;
        }

        $version = ConsumedAggregateVersion::query()
            ->lockForUpdate()
            ->find($this->streamKey($inbox));

        return $version !== null && $inbox->aggregate_version <= $version->last_version;
    }

    private function rememberAggregateVersion(InboxEvent $inbox): void
    {
        if (! $this->hasOrderedAggregate($inbox)) {
            return;
        }

        ConsumedAggregateVersion::query()->updateOrCreate(
            ['stream_key' => $this->streamKey($inbox)],
            [
                'producer' => $inbox->producer,
                'aggregate_type' => $inbox->aggregate_type,
                'aggregate_id' => $inbox->aggregate_id,
                'last_version' => $inbox->aggregate_version,
            ],
        );
    }

    private function hasOrderedAggregate(InboxEvent $inbox): bool
    {
        return $inbox->producer !== null
            && $inbox->aggregate_type !== null
            && $inbox->aggregate_id !== null
            && $inbox->aggregate_version !== null;
    }

    private function streamKey(InboxEvent $inbox): string
    {
        return hash('sha256', implode('|', [
            $inbox->producer,
            $inbox->aggregate_type,
            $inbox->aggregate_id,
        ]));
    }
}
