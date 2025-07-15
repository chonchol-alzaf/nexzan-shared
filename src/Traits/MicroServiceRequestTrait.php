<?php

namespace Nexzan\Shared\Traits;

use Nexzan\Shared\Exceptions\CustomException;

trait MicroServiceRequestTrait
{
    public function getRequestConfig($service_name,$config_file = 'service-core')
    {
        $config_key = "{$config_file}.{$service_name}";
        $service = config($config_key);

        if (empty($service)) {
            throw new CustomException('Service credential not found!', 500);
        }

        return [
            'base_url' => $service['url'],
            'config' => [
                'accept' => 'application/json',
                'x-api-key' => $service['api_key'],
                'x-api-secret' => $service['secret'],
            ],
        ];
    }
}
