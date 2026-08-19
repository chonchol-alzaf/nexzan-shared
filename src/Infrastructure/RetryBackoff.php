<?php

namespace Nexzan\Shared\Infrastructure;

final class RetryBackoff
{
    public static function seconds(string $configKey, int $attempt): int
    {
        $values = array_values((array) config($configKey, [10, 30, 60, 120, 300, 600]));

        if ($values === []) {
            return 60;
        }

        return (int) $values[min(max(1, $attempt) - 1, count($values) - 1)];
    }
}
