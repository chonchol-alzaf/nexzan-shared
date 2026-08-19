<?php

namespace Nexzan\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class OutboxAggregateVersion extends Model
{
    protected $primaryKey = 'stream_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'stream_key',
        'producer',
        'aggregate_type',
        'aggregate_id',
        'last_version',
    ];

    protected function casts(): array
    {
        return [
            'last_version' => 'integer',
        ];
    }

    public static function next(
        string $producer,
        string $aggregateType,
        string $aggregateId,
        ?int $requestedVersion = null,
    ): int {
        $streamKey = hash('sha256', implode('|', [$producer, $aggregateType, $aggregateId]));

        self::query()->firstOrCreate(
            ['stream_key' => $streamKey],
            [
                'producer' => $producer,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'last_version' => 0,
            ],
        );

        /** @var self $stream */
        $stream = self::query()->lockForUpdate()->findOrFail($streamKey);
        $nextVersion = $requestedVersion ?? ($stream->last_version + 1);

        if ($nextVersion <= $stream->last_version) {
            throw new InvalidArgumentException(
                "Aggregate version {$nextVersion} must be greater than {$stream->last_version}."
            );
        }

        $stream->forceFill(['last_version' => $nextVersion])->save();

        return $nextVersion;
    }
}
