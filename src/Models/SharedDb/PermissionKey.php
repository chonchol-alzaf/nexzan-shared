<?php

namespace Nexzan\Shared\Models\SharedDb;

use Illuminate\Database\Eloquent\Model;
use Nexzan\Shared\Traits\HasUuidsTrait;

class PermissionKey extends Model
{
    use HasUuidsTrait;

    protected $connection = 'shared_db';
}
