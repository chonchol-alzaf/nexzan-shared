<?php

namespace Nexzan\Shared\Http\Middleware;

use Closure;
use Nexzan\Shared\Facades\Auth;
use Nexzan\Shared\Exceptions\CustomException;

class EnsureInternalUser
{
    public function handle($request, Closure $next)
    {
        if (Auth::type() !== 'user') {
            throw new CustomException('User authentication is required.', 403);
        }

        return $next($request);
    }
}
