<?php

namespace Nexzan\Shared\Models\SharedDb;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Team extends Model
{
    protected $connection = 'shared_db';

    protected $fillable = [
        'id',
        'title',
        'email',
        'status',
    ];
}
