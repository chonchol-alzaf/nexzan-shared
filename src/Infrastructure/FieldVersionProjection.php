<?php

namespace Nexzan\Shared\Infrastructure;

use LogicException;
use Nexzan\Shared\Enums\InboxStatus;
use Nexzan\Shared\Models\ConsumedAggregateVersion;
use Nexzan\Shared\Models\InboxEvent;

/** Apply independently ordered fields inside the resource's Inbox transaction. */
class FieldVersionProjection
{
    public function apply(string $group, string $type, string $id, callable $apply, array $historyEvents = []): bool
    {
        $inbox = app(InboxExecutionContext::class)->inbox;
        if (! $inbox) {
            $apply(); // Compatibility for explicit, synchronous domain calls.

            return true;
        }
        if (($inbox->aggregate_id !== null && (string) $inbox->aggregate_id !== $id)
            || ($inbox->aggregate_type !== null && $inbox->aggregate_type !== $type)) {
            throw new LogicException('Projection identity differs from its message aggregate.');
        }
        $producer = (string) $inbox->producer;
        $version = $this->lock($producer, $group, $type, $id);
        if ($producer === '' && $this->hasKnownVersion($group, $type, $id, $historyEvents)) {
            return false; // An unidentified legacy producer cannot reset an ordered projection.
        }
        // A locking read uses the current committed state, including on MySQL REPEATABLE READ.
        $known = InboxEvent::where('producer', $producer)->where('aggregate_type', $type)
            ->where('aggregate_id', $id)->whereIn('event_type', $historyEvents)
            ->where('status', InboxStatus::Completed->value)->whereNotNull('aggregate_version')
            ->orderByDesc('aggregate_version')->lockForUpdate()->first(['aggregate_version']);
        $last = max($version->last_version, $known?->aggregate_version ?? 0);
        if ($last > $version->last_version) {
            $version->update(['last_version' => $last]);
        }
        if ($inbox->aggregate_version === null) {
            if ($last > 0) {
                return false;
            }
        } elseif ($inbox->aggregate_version <= $last) {
            return false;
        }
        $apply();
        if ($inbox->aggregate_version !== null) {
            $version->update(['last_version' => $inbox->aggregate_version]);
        }

        return true;
    }

    private function hasKnownVersion(string $group, string $type, string $id, array $historyEvents): bool
    {
        $versions = ConsumedAggregateVersion::where('aggregate_type', $type)->where('aggregate_id', $id)
            ->where('last_version', '>', 0)->lockForUpdate()->get();
        foreach ($versions as $version) {
            if ($version->stream_key === hash('sha256', implode('|', [$version->producer, $group, $type, $id]))) {
                return true;
            }
        }

        return InboxEvent::where('aggregate_type', $type)->where('aggregate_id', $id)
            ->whereIn('event_type', $historyEvents)->where('status', InboxStatus::Completed->value)
            ->whereNotNull('aggregate_version')->lockForUpdate()->first(['id']) !== null;
    }

    public function lock(string $producer, string $group, string $type, string $id): ConsumedAggregateVersion
    {
        app(ResourceDeletionGuard::class)->lock($type, $id);
        $key = hash('sha256', implode('|', [$producer, $group, $type, $id]));
        ConsumedAggregateVersion::insertOrIgnore([
            'stream_key' => $key, 'producer' => $producer, 'aggregate_type' => $type,
            'aggregate_id' => $id, 'last_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ConsumedAggregateVersion::whereKey($key)->lockForUpdate()->firstOrFail();
    }
}
