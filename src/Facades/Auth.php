<?php

namespace Nexzan\Shared\Facades;

use Illuminate\Support\Facades\Facade;

class Auth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'authUser';
    }
}
