<?php

namespace Nexzan\Shared\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Nexzan\Shared\Enums\InboxStatus;
use Nexzan\Shared\Models\ConsumedAggregateVersion;
use Nexzan\Shared\Models\InboxEvent;
use Nexzan\Shared\Models\OutboxEvent;

class ResourceDeletionGuard
{
    public function isDeleted(string $type, string $id): bool
    {
        $this->lock($type, $id);

        return $this->wasDeleted($type, $id);
    }

    /** Called inside the Inbox transaction, before any resource mutation. */
    public function shouldSkip(InboxEvent $inbox): bool
    {
        // Event names contain dots, so read their map without dot traversal.
        $message = config('rabbitmq.resource_messages', [])[$inbox->event_type] ?? null;
        if (! $message) {
            return false;
        }

        $id = data_get($inbox->payload, $message['id']);
        if (! is_string($id) && ! is_int($id)) {
            return false; // Let the domain handler report an invalid payload.
        }

        $parentId = null;
        if (isset($message['parent'])) {
            $parentId = data_get($inbox->payload, $message['parent']['id']);
            if (is_string($parentId) || is_int($parentId)) {
                $this->lock($message['parent']['type'], (string) $parentId);
            }
        }

        $this->lock($message['type'], (string) $id);

        // Acquire both locks before reading any delivery ledger, so waiting for
        // a child delete cannot leave us using an older MySQL read snapshot.
        if ((is_string($parentId) || is_int($parentId))
            && $this->wasDeleted($message['parent']['type'], (string) $parentId)) {
            return true;
        }

        return $this->wasDeleted($message['type'], (string) $id);
    }

    /** Serialize create/delete even when the resource row does not exist yet. */
    public function lock(string $type, string $id): void
    {
        $key = hash('sha256', 'resource-lifecycle|'.$type.'|'.$id);
        ConsumedAggregateVersion::query()->insertOrIgnore([
            'stream_key' => $key,
            'producer' => 'resource-lifecycle',
            'aggregate_type' => $type,
            'aggregate_id' => $id,
            'last_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ConsumedAggregateVersion::query()->whereKey($key)->lockForUpdate()->firstOrFail();
    }

    private function wasDeleted(string $type, string $id): bool
    {
        $resource = config('rabbitmq.retained_resources', [])[$type] ?? null;
        if (! $resource) {
            return false;
        }

        /** @var Model $model */
        $model = new $resource['model'];
        // A current locking read also serializes against source-service cleanup
        // transactions that lock the model row without an Inbox message.
        $row = $model->newQueryWithoutScopes()->whereKey($id)->lockForUpdate()
            ->first([$model->getKeyName(), 'deleted_at']);
        if ($row?->deleted_at !== null) {
            return true;
        }

        // Resource rows expire after six months. Existing delivery ledgers keep
        // late events from recreating them; no separate tombstone table is used.
        $path = str_replace('.', '->', $resource['deleted_id']);
        $received = InboxEvent::query()
            ->where('event_type', $resource['deleted_event'])
            ->where('status', InboxStatus::Completed->value)
            ->where(function ($query) use ($type, $id, $path): void {
                $query->where(function ($query) use ($type, $id): void {
                    $query->where('aggregate_type', $type)->where('aggregate_id', $id);
                })->orWhere(function ($query) use ($id, $path): void {
                    $query->whereNull('aggregate_id')->where('payload->'.$path, $id);
                });
            })->lockForUpdate()->first(['id']) !== null;
        if ($received) {
            return true;
        }

        $outboxPath = str_replace('.', '->', preg_replace('/^resource\./', '', $resource['deleted_id']));

        return OutboxEvent::query()
            ->where('event_type', $resource['deleted_event'])
            ->where(function ($query) use ($type, $id, $outboxPath): void {
                $query->where(function ($query) use ($type, $id): void {
                    $query->where('aggregate_type', $type)->where('aggregate_id', $id);
                })->orWhere(function ($query) use ($id, $outboxPath): void {
                    $query->whereNull('aggregate_id')->where('payload->'.$outboxPath, $id);
                });
            })->lockForUpdate()->first(['id']) !== null;
    }
}
