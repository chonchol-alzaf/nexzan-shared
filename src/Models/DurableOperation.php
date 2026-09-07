<?php

namespace Nexzan\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use LogicException;
use Nexzan\Shared\Infrastructure\InboxExecutionContext;

class DurableOperation extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['job_payload' => 'encrypted', 'started_at' => 'datetime'];
    }

    /** Only application-created jobs are stored; never accept serialized broker input. */
    public static function dispatch(object $job, string $operationKey): void
    {
        $eventId = app(InboxExecutionContext::class)->eventId;
        if ($eventId === null) {
            Bus::dispatch($job->afterCommit());

            return;
        }

        if (DB::transactionLevel() === 0) {
            throw new LogicException('Inbox operations must be recorded in the handler transaction.');
        }

        static::query()->firstOrCreate(
            ['deduplication_key' => hash('sha256', $eventId.'|'.$operationKey)],
            [
                'event_id' => $eventId,
                'operation_key' => $operationKey,
                'job_payload' => base64_encode(serialize($job)),
                'status' => 'pending',
            ],
        );
    }
}
