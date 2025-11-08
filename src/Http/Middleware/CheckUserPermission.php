<?php
namespace Nexzan\Shared\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use Nexzan\Shared\Traits\RolePermissionTrait;
use Illuminate\Validation\UnauthorizedException;

class CheckUserPermission
{
    use RolePermissionTrait;
    public function handle($request, Closure $next, ...$permissionKeys)
    {
        $lastParam = strtolower(end($permissionKeys));
        $mode = in_array($lastParam, ['any', 'all']) ? array_pop($permissionKeys) : 'any';

        if (! $this->userHasAnyPermission($permissionKeys,$mode)) {
            throw new UnauthorizedException('Unauthorized: You don’t have permission to perform this action.', 403);
        }
        return $next($request);
    }

    private function userHasAnyPermission(array $permissionKeys,$mode)
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

        if ($mode === 'any') {
            return $expectedPermissions->some(fn($key) => $user_permission_keys->contains($key));
        }

        // “all” → all must match
        return $expectedPermissions->every(fn($key) => $user_permission_keys->contains($key));
    }
}
