<?php
namespace Nexzan\Shared\Models\SharedDb;

use Illuminate\Database\Eloquent\Model;
use Nexzan\Shared\Traits\HasUuidsTrait;

class DefaultRole extends Model
{
    use HasUuidsTrait;

    protected $connection = 'shared_db';

    public function permissionKeys()
    {
        return $this->belongsToMany(PermissionKey::class, 'default_role_permissions');
    }

}
