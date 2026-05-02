<?php
namespace Nexzan\Shared\Http\Middleware;

use App\Traits\NewRolePermissionTrait;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Nexzan\Shared\Exceptions\CustomException;

class CheckGatewayUserPermission
{
    use NewRolePermissionTrait;
    public function handle($request, Closure $next, ...$permissionKeys)
    {
        Log::debug($permissionKeys);
        if (! $this->userHasAnyPermission($permissionKeys)) {
            throw new CustomException('Unauthorized: You don’t have permission to perform this action.', 403);
        }
        return $next($request);
    }

    private function userHasAnyPermission(array $permissionKeys)
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }
        
        $user_role_id = $this->getUserRoleId($user->id, $user->current_team_id);
        $userPermissions = $this->getRolePermissions($user_role_id);

        return ! empty(array_intersect($permissionKeys, $userPermissions));
    }
}
