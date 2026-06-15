<?php

namespace Nexzan\Shared\Enums;

enum TeamStatusEnum: int
{
    case ACTIVE = 1;
    case TRIAL = 2;
    case INACTIVE = 3;
    case SUSPEND = 4;
    case TRIAL_ENDED = 5;

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
