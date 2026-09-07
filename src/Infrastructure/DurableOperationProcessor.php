<?php

namespace Nexzan\Shared\Infrastructure;

use Illuminate\Support\Facades\DB;
use Nexzan\Shared\Models\DurableOperation;
use RuntimeException;
use Throwable;

class DurableOperationProcessor
{
    public function processNext(): bool
    {
        $operation = DB::transaction(function () {
            $operation = DurableOperation::query()->where('status', 'pending')
                ->oldest('id')->lockForUpdate()->first();
            if ($operation) {
                $operation->update(['status' => 'processing', 'started_at' => now(),
                    'attempts' => $operation->attempts + 1]);
            }

            return $operation;
        });
        if (! $operation) {
            return false;
        }

        try {
            // Encrypted, locally constructed job payload, never a RabbitMQ payload.
            $job = unserialize(base64_decode($operation->job_payload, true));
            if (! is_object($job) || ! method_exists($job, 'handle')) {
                throw new RuntimeException('Invalid durable operation job.');
            }
            app()->call([$job, 'handle']);
            $operation->update(['status' => 'completed', 'completed_at' => now(), 'last_error' => null]);
        } catch (Throwable $exception) {
            // A remote effect may have succeeded before an exception. Never blindly replay it.
            $operation->update(['status' => 'needs_review', 'last_error' => mb_substr($exception->getMessage(), 0, 4000)]);
            report($exception);
        }

        return true;
    }
}
