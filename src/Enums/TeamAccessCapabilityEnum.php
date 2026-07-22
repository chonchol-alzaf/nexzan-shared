<?php

namespace Nexzan\Shared\Enums;

enum TeamAccessCapabilityEnum: string
{
    case VIEW_DASHBOARD = 'view_dashboard';
    case VIEW_RESOURCES = 'view_resources';
    case CREATE_RESOURCE = 'create_resource';
    case USE_RESOURCE = 'use_resource';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function getValues(): array
    {
        return self::values();
    }
}
