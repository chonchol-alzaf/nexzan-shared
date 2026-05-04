<?php

namespace Nexzan\Shared\Traits;

use Nexzan\Shared\Exceptions\CustomException;
use Nexzan\Shared\Traits\InternalJwtTrait;

trait MicroServiceRequestTrait
{
    use InternalJwtTrait;
    public function getRequestConfig($service_name,$config_file = 'service-core')
    {
        $config_key = "{$config_file}.{$service_name}";
        $service = config($config_key);

        if (empty($service)) {
            throw new CustomException('Service credential not found!', 500);
        }

        $jwt_meta   = [
            'api_key'    => $service['api_key'],

            'api_secret' => $service['secret'],
            'request_id' => Str::uuid()->toString(),
        ];

        $internal_token = $this->generateJWTToken($jwt_meta, 'HS256');

        return [
            'base_url' => $service['url'],
            'internal_token'=> ['X-Internal-Token' => $internal_token]
        ];
    }
}
