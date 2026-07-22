<?php

namespace Nexzan\Shared\Enums;

enum AccountStatusEnum: string
{
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case TERMINATED = 'terminated';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function getValues(): array
    {
        return self::values();
    }
}
