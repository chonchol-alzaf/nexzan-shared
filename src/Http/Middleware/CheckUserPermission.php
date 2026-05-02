<?php
namespace Nexzan\Shared\Http\Middleware;

use Closure;
use Nexzan\Shared\Facades\Auth;
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
        $userPermissions = Auth::userPermissions();

        return ! empty(array_intersect($permissionKeys, $userPermissions));


        // Resource Permission Override
        // $hasOverride = ResourcePermission::where("team_id", userTeamId())
        // ->where("user_id", userId())
        // ->where("effect", ResourcePermission::PERMISSION_TYPE['allow'])
        // ->whereHas("permissionKey", function ($q) use ($permissionKeys) {
        //     $q->whereIn("name", $permissionKeys);
        // })
        // ->exists();

        // return (bool) $hasOverride;
    }
}
