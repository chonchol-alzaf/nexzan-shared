<?php

namespace Nexzan\Shared\Models\SharedDb;

use Illuminate\Database\Eloquent\Model;
use Nexzan\Shared\Traits\HasUuidsTrait;

class ResourcePermission extends Model
{

    protected $connection = 'shared_db';

    public const RESOURCE_TYPE = [
        "atom"=> "atom",
        "site"=> "site"
    ];

    public const PERMISSION_TYPE = [
        "allow"=> "allow",
        "deny"=> "deny"
    ];


    public function permissionKey()
    {
        return $this->belongsTo(PermissionKey::class, 'permission_key_id', 'id');
    }


    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
