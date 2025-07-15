<?php

namespace Nexzan\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Team extends Model
{
    use Notifiable;

    protected $fillable = [
        'id',
        'name',
        'slug',
        'email',
        'status',
    ];
}
