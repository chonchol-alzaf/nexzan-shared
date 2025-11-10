<?php

namespace App\Models\SharedDb;

use Illuminate\Database\Eloquent\Model;
use Nexzan\Shared\Traits\HasUuidsTrait;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ResourcePermission extends Model
{
    use HasUuidsTrait;

    protected $connection = 'shared_db';

    protected $fillable = [
    ];

    public const RESOURCE_TYPE = [
        "atom"=> "atom",
        "site"=> "site"
    ];

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    public function permissionKey()
    {
        return $this->belongsTo(PermissionKey::class, 'permission_key_id', 'id');
    }


    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
