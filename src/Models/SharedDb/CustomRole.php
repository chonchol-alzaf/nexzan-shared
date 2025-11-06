<?php
namespace Nexzan\Shared\Models\SharedDb;

use Illuminate\Database\Eloquent\Model;
use Nexzan\Shared\Traits\HasUuidsTrait;

class CustomRole extends Model
{
    use HasUuidsTrait;
    protected $connection = 'shared_db';

    public function permissionKeys()
    {
        return $this->belongsToMany(PermissionKey::class, 'custom_role_permissions')->withTimestamps();
    }
}
