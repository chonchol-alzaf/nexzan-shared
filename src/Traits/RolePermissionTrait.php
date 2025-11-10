<?php
namespace Nexzan\Shared\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nexzan\Shared\Exceptions\CustomException;
use Nexzan\Shared\Models\SharedDb\CustomRole;
use Nexzan\Shared\Models\SharedDb\DefaultRole;
use Nexzan\Shared\Models\SharedDb\TeamUser;

trait RolePermissionTrait
{
    // tags
    protected const TAG_DEFAULT_ROLES = 'default-roles-tag';
    protected const TAG_PERMISSIONS   = 'permissions-key-tag';

    // Cache Keys
    protected const KEY_PERMISSION_IDS     = 'permission-ids';
    protected const KEY_PERMISSION_NAMES   = 'permission-names';
    protected const KEY_DEFAULT_ROLE_IDS   = 'default-role-ids';
    protected const KEY_DEFAULT_ROLE_NAMES = 'default-role-names';
    protected const KEY_CUSTOM_ROLE_NAMES  = 'custom-role-names';

    protected const KEY_USER_ROLE = 'user-role:';

    private function getCustomRoleCacheTagName()
    {
        $tag = "custom-roles-tag:" . userTeamId();
        return $tag;
    }

    private function getCustomRoleCacheKey($role_id)
    {
        return "custom-roles:" . $role_id;
    }

    private function getDefaultRoleCacheKey($role_id)
    {
        return "default-roles:" . $role_id;
    }

    private function getUserRoleCacheKey($user_id, $team_id)
    {
        return "user-role:{$team_id}:{$user_id}";
    }

    public function getUserRoleId($user_id, $team_id)
    {
        return Cache::store('shared_redis')->rememberForever($this->getUserRoleCacheKey($user_id, $team_id), function () use ($user_id, $team_id) {
            return TeamUser::where('user_id', $user_id)
                ->where("team_id", $team_id)
                ->value("role_id");
        });

    }

    public function getRolePermissions($role_id)
    {
        $role = $this->getDefaultRole($role_id) ?? $this->getCustomRole($role_id);

        if (! $role) {
            throw new CustomException('Role is not valid!', 400);
        }

        return Cache::store('shared_redis')->tags([self::TAG_DEFAULT_ROLES, $this->getCustomRoleCacheTagName()])->rememberForever($role_id, function () use ($role) {
            $role->loadMissing('permissionKeys:name');
            return $role->permissionKeys->pluck('name');
        });
    }

    public function getDefaultRole($role_id)
    {
        Cache::store("shared_redis")->tags([self::TAG_DEFAULT_ROLES])->flush();

        $cache_data = Cache::store('shared_redis')->tags([self::TAG_DEFAULT_ROLES])->rememberForever($this->getDefaultRoleCacheKey($role_id), function () use ($role_id) {
            $result = DefaultRole::select('id', 'name', 'short_description', DB::raw('true as is_system_default'))
                ->where("id", $role_id)
                ->first();
            return $result ?? ['_not_found' => true];
        });

        if (is_array($cache_data) && isset($cache_data['_not_found'])) {
            return null;
        }

        return $cache_data;
    }

    public function getCustomRole($role_id)
    {
        $cache_key = $this->getCustomRoleCacheKey($role_id);

        $cache_data = Cache::store('shared_redis')->tags([$this->getCustomRoleCacheTagName()])->rememberForever($cache_key, function () use ($role_id) {
            $result = CustomRole::select('id', 'name', 'short_description', DB::raw('false as is_system_default'))
                ->where("id", $role_id)
                ->first();
            return $result ?? ['_not_found' => true];
        });

        if (is_array($cache_data) && isset($cache_data['_not_found'])) {
            return null;
        }

        return $cache_data;
    }
}
