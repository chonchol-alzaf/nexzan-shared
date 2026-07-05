<?php

namespace Nexzan\Shared\Enums;

enum TeamStatusEnum: int
{
    case ACTIVE = 1;
    case TRIAL = 2;
    case TEMP_SUSPENDED = 3; // bill not paid, but can still use the service and can be reactivated by paying the bill
    case SUSPEND = 4; // suspend by the admin for illegal activities, can be reactivated by the admin
    case TRIAL_ENDED = 5;
    case TERMINATED = 6; // soft delete team from db. that means end of the team

    public static function getKey(self|int|string|null $value): ?string
    {
        if ($value instanceof self) {
            return $value->name;
        }

        return self::tryFrom((int) $value)?->name;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function getValues(): array
    {
        return self::values();
    }
}
