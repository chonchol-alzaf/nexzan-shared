<?php
namespace Nexzan\Shared\Traits;

use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Nexzan\Shared\Exceptions\CustomException;

trait InternalJwtTrait
{

    protected function getPrivateJwtKey()
    {
        $privateKeyPath = storage_path('jwt-private.pem');

        if (! file_exists($privateKeyPath)) {
            report(new \RuntimeException("JWT private key file not found at: {$privateKeyPath}"));
            throw new CustomException("Authentication configuration is invalid.", 500);
        }

        $privateKey = file_get_contents($privateKeyPath);

        if ($privateKey === false) {
            report(new \RuntimeException("Unable to read JWT private key file at: {$privateKeyPath}"));
            throw new CustomException("Authentication service is temporarily unavailable.", 500);
        }

        return $privateKey;
    }

    protected function getPublicJwtKey()
    {
        $publicKeyPath = storage_path('jwt-public.pem');

        if (! file_exists($publicKeyPath)) {
            report(new \RuntimeException("JWT public key file not found at: {$publicKeyPath}"));
            throw new CustomException("Authentication configuration is invalid.", 500);
        }

        $publicKey = file_get_contents($publicKeyPath);

        if ($publicKey === false) {
            report(new \RuntimeException("Unable to read JWT public key file at: {$publicKeyPath}"));
            throw new CustomException("Authentication service is temporarily unavailable.", 500);
        }

        return $publicKey;
    }

    public function generateJWTToken(array $payload, $alg = "HS256"): string
    {
        $now = time();

        $data = [
            'iss'  => 'gateway',
            'aud'  => 'internal-service',
            'iat'  => $now,
            'nbf'  => $now,
            'exp'  => $now + 40, // 40 seconds
            'data' => $payload,
        ];

        if ($alg == "HS256") {
            return JWT::encode($data, config("nexzan-shared.jwt_secret"), 'HS256');
        } elseif ($alg == 'RS256') {
            return JWT::encode($data, $this->getPrivateJwtKey(), 'RS256');
        } else {
            throw new CustomException("Invalid algo", 500);
        }

    }

    public function resolveInternalToken($request, $alg = "HS256")
    {
        $token = $request->header('X-Internal-Token');

        if (! $token) {
            throw new CustomException("Unauthorized requests", 401);
        }

        if ($alg == "HS256") {
            $keyMaterial = config("nexzan-shared.jwt_secret");
        } elseif ($alg == 'RS256') {
            $keyMaterial = $this->getPublicJwtKey();
        } else {
            throw new CustomException("Invalid request", 500);
        }

        try
        {
            $payload = JWT::decode($token, new Key($keyMaterial, $alg));
        } catch (Exception $e) {
            report(new \RuntimeException($e->getMessage()));
            throw new CustomException("Invalid request", 500);
        }

        if (! isset($payload->data)) {
            throw new CustomException("Invalid request", 500);
        }

        $jwtData = (array) $payload->data;

        $request->attributes->set('auth_user', $jwtData['user']);
        $request->attributes->set('team', $jwtData['team']);

        $request->attributes->set('request_id', $jwtData['request_id'] ?? null);
        $request->attributes->set('api_key', $jwtData['api_key'] ?? null);
        $request->attributes->set('api_secret', $jwtData['api_secret'] ?? null);
    }
}
