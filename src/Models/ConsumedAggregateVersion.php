<?php

namespace Nexzan\Shared\Models;

use Illuminate\Database\Eloquent\Model;

class ConsumedAggregateVersion extends Model
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
        return ['last_version' => 'integer'];
    }
}
