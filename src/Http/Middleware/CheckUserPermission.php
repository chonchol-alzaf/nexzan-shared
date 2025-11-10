<?php
namespace Nexzan\Shared\Http\Middleware;

use Closure;
use Nexzan\Shared\Exceptions\CustomException;
use Nexzan\Shared\Traits\RolePermissionTrait;

class CheckUserPermission
{
    use RolePermissionTrait;
    public function handle($request, Closure $next, ...$permissionKeys)
    {
        if (! $this->userHasAnyPermission($permissionKeys)) {
            throw new CustomException('Unauthorized: You don’t have permission to perform this action.', 403);
        }
        return $next($request);
    }

    private function userHasAnyPermission(array $permissionKeys)
    {
        $user_role_id = $this->getUserRoleId(userId(), userTeamId());

        if (! $user_role_id) {
            return false;
        }

        $expectedPermissions = collect($permissionKeys)
            ->flatMap(fn($item) => explode(',', $item)) // split comma-separated values
            ->map(fn($item) => trim($item))
            ->filter()
            ->unique()
            ->values();

        $user_permission_keys = $this->getRolePermissions($user_role_id);
        

        if ($expectedPermissions->some(fn($key) => $user_permission_keys->contains($key))) {
            return true;
        }

        // TODO: in future we will cache this query
        $hasOverride = ResourcePermission::where("team_id", userTeamId())
            ->where("user_id", userId())
            ->where("effect", ResourcePermission::PERMISSION_TYPE['allow'])
            ->whereHas("permissionKey", function ($q) use ($permissionKeys) {
                $q->whereIn("name", $permissionKeys);
            })
            ->exists();

        return (bool) $hasOverride;

    }
}

