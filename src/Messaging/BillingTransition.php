<?php

namespace Nexzan\Shared\Messaging;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Immutable billing facts. The sequence is independent of aggregate_version. */
final class BillingTransition
{
    public static function validate(array $facts): array
    {
        foreach (['schema_version', 'event_id', 'operation_id', 'resource_type', 'resource_id', 'team_id', 'sequence', 'predecessor', 'kind', 'effective_at', 'snapshot'] as $key) {
            if (! array_key_exists($key, $facts)) {
                throw new InvalidArgumentException("Missing billing transition field: {$key}");
            }
        }
        if ($facts['schema_version'] !== 1 || ! in_array($facts['resource_type'], ['server', 'volume'], true)
            || ! in_array($facts['kind'], ['start', 'scale', 'delete', 'noop'], true)
            || ! is_int($facts['sequence']) || $facts['sequence'] < 1
            || $facts['predecessor'] !== $facts['sequence'] - 1
            || ! is_array($facts['snapshot']) || ! $facts['event_id'] || ! $facts['operation_id']
            || ! $facts['resource_id'] || ! $facts['team_id']) {
            throw new InvalidArgumentException('Invalid billing transition identity or sequence.');
        }
        if (! is_string($facts['effective_at']) || ! preg_match('/(?:Z|[+-]\\d{2}:\\d{2})$/', $facts['effective_at'])) {
            throw new InvalidArgumentException('Billing effective_at requires an explicit UTC offset.');
        }
        CarbonImmutable::parse($facts['effective_at']);

        return $facts;
    }

    public static function fingerprint(array $facts): string
    {
        $sort = function (array $value) use (&$sort): array {
            ksort($value);
            foreach ($value as &$child) {
                if (is_array($child)) {
                    $child = $sort($child);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($facts), JSON_THROW_ON_ERROR));
    }

    public static function hours($start, $end): float
    {
        return max(0, CarbonImmutable::parse($start)->diffInSeconds(CarbonImmutable::parse($end), false)) / 3600;
    }
}
