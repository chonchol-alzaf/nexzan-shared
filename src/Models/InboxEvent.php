<?php

namespace Nexzan\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Nexzan\Shared\Enums\InboxStatus;

class InboxEvent extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'event_id',
        'event_type',
        'exchange',
        'routing_key',
        'queue_name',
        'producer',
        'aggregate_type',
        'aggregate_id',
        'aggregate_version',
        'payload',
        'status',
        'attempts',
        'available_at',
        'dispatched_at',
        'processing_started_at',
        'processed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => InboxStatus::class,
            'aggregate_version' => 'integer',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
