<?php
namespace Nexzan\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Nexzan\Shared\Traits\InternalJwtTrait;
use Illuminate\Auth\Access\AuthorizationException;

class KeyAuthMiddleware
{
    use InternalJwtTrait;

    public function handle(Request $request, Closure $next)
    {
        if (config('nexzan-shared.require_jwt_validations') == false) {
            $routeName = request()->route()?->getName();
            if (str_contains($routeName, 'in.house') || str_contains($routeName, 'third-party.')) {
                return $next($request);
            }
        }

        $this->resolveInternalToken($request, "HS256");

        if (config("nexzan-shared.require_api_credentials") === false) {
            return $next($request);
        }

        $key    = $request->attributes->get('api_key');
        $secret = $request->attributes->get('api_secret');

        $clientIp = $request->ip(); // Get request IP

        if (! $key || ! $secret) {
            throw new AuthorizationException('Unauthorized. Missing key or secret.');
        }

        $cache_key = "api_key_{$key}";

        $apiKeyModel = config('nexzan-shared.models.api_key');

        if (! class_exists($apiKeyModel)) {
            throw new \RuntimeException("Model class [$apiKeyModel] does not exist.");
        }

        $apiKey = Cache::rememberForever($cache_key, function () use ($key, $apiKeyModel) {
            return $apiKeyModel::select("id", "key", "secret", "whitelist")
                ->where('key', $key)
                ->where('is_active', 1)
                ->first();
        });

        $hashedInput = hash_hmac('sha256', $secret, config("nexzan-shared.secret_pepper"));

        if (! $apiKey || $hashedInput !== $apiKey->secret) {
            throw new AuthorizationException('Unauthorized. Invalid key or secret.');
        }

        // Validate IP whitelist
        if (config("nexzan-shared.enable_ip_whitelist") == true && ! $apiKey->isIpAllowed($clientIp)) {
            throw new AuthorizationException('Forbidden. IP not allowed.');
        }

        // Allow the request to proceed
        return $next($request);
    }
}
