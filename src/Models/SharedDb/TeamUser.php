<?php

namespace Nexzan\Shared\Models\SharedDb;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class TeamUser extends Model
{
    protected $connection = 'shared_db';
}
