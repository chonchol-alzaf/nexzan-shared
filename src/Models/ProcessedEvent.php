<?php
namespace Nexzan\Shared\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedEvent extends Model
{
    protected $fillable = [
        'event_id',
        'event',
    ];

}
