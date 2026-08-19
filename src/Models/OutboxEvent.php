<?php

namespace Nexzan\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nexzan\Shared\Enums\OutboxStatus;

class OutboxEvent extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'event_id',
        'event_type',
        'exchange',
        'routing_key',
        'producer',
        'aggregate_type',
        'aggregate_id',
        'aggregate_version',
        'payload',
        'status',
        'attempts',
        'next_attempt_at',
        'publishing_started_at',
        'published_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxStatus::class,
            'aggregate_version' => 'integer',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'publishing_started_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public static function record(
        string $eventType,
        string $exchange,
        array $payload,
        ?string $aggregateType = null,
        string|int|null $aggregateId = null,
        ?int $aggregateVersion = null,
        ?string $eventId = null,
        ?string $producer = null,
    ): self {
        if (($aggregateType === null) !== ($aggregateId === null)) {
            throw new InvalidArgumentException('aggregateType and aggregateId must be provided together.');
        }

        if ($aggregateVersion !== null && $aggregateVersion < 1) {
            throw new InvalidArgumentException('aggregateVersion must be a positive integer.');
        }

        $producer ??= (string) config('rabbitmq.producer');

        if (trim($producer) === '') {
            throw new InvalidArgumentException('producer must be a non-empty string.');
        }

        $aggregateId = $aggregateId === null ? null : (string) $aggregateId;

        return DB::transaction(function () use (
            $eventType,
            $exchange,
            $payload,
            $aggregateType,
            $aggregateId,
            $aggregateVersion,
            $eventId,
            $producer,
        ): self {
            if ($aggregateType !== null && $aggregateId !== null) {
                $aggregateVersion = OutboxAggregateVersion::next(
                    producer: $producer,
                    aggregateType: $aggregateType,
                    aggregateId: $aggregateId,
                    requestedVersion: $aggregateVersion,
                );
            }

            return self::query()->create([
                'event_id' => $eventId ?? (string) Str::ulid(),
                'event_type' => $eventType,
                'exchange' => $exchange,
                'routing_key' => $eventType,
                'producer' => $producer,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'aggregate_version' => $aggregateVersion,
                'payload' => $payload,
                'status' => OutboxStatus::Pending,
                'attempts' => 0,
            ]);
        }, 3);
    }
}
