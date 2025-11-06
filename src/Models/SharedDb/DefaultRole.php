<?php
namespace Nexzan\Shared\Models\SharedDb;

use App\Models\SharedDb\TeamUser;
use Illuminate\Database\Eloquent\Model;
use Nexzan\Shared\Traits\HasUuidsTrait;

class DefaultRole extends Model
{

    protected $connection = 'shared_db';

    public function permissionKeys()
    {
        return $this->belongsToMany(PermissionKey::class, 'default_role_permissions');
    }

}
