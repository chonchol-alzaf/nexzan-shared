<?php

namespace Nexzan\Shared\Enums;

enum BillingStatusEnum: string
{
    case TRIALING = 'trialing';
    case CURRENT = 'current';
    case HOLD = 'hold';
    case SUSPENDED = 'suspended';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function getValues(): array
    {
        return self::values();
    }
}
