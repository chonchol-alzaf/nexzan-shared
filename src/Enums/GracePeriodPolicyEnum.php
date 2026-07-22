<?php

namespace Nexzan\Shared\Enums;

enum GracePeriodPolicyEnum: string
{
    case FULL_ACCESS = 'full_access';
    case EXISTING_RESOURCES_ONLY = 'existing_resources_only';
    case VIEW_ONLY = 'view_only';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function getValues(): array
    {
        return self::values();
    }
}
