<?php

namespace Nexzan\Shared\Traits;

use App\Models\ProcessedEvent;


trait HandleProcessedEvent
{

    public function isProcessed($payload): bool
    {
        $eventId = $payload['event_id'] ?? null;
        if (! $eventId) {
            return false;
        }

        return ProcessedEvent::where('event_id', $eventId)->exists();
    }

    public function markAsProcessed($payload): void
    {
        $event   = $payload['event'];
        $eventId = $payload['event_id'] ?? null;

        if (! $eventId) {
            return;
        }

        ProcessedEvent::create([
            'event_id' => $eventId,
            'event'    => $event,
        ]);
    }
}
