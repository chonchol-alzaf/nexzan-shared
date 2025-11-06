<?php

namespace Nexzan\Shared\Models\SharedDb;

use Illuminate\Database\Eloquent\Model;

class CustomRolePermission extends Model
{
    protected $connection = 'shared_db';

    public Const ACCESS_TYPE = [
        "allow"=> "allow",
        "deny"=> "deny"
    ];
}
