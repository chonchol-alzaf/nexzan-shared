<?php

namespace Nexzan\Shared\Http\Middleware;

use Closure;
use Nexzan\Shared\Facades\Auth;
use Nexzan\Shared\Exceptions\CustomException;

class EnsureInternalAdmin
{
    public function handle($request, Closure $next)
    {
        if (data_get(Auth::authUser(), 'type') !== 'admin') {
            throw new CustomException('Admin authentication is required.', 403);
        }

        return $next($request);
    }
}
