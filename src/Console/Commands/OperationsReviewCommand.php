<?php

namespace Nexzan\Shared\Console\Commands;

use Illuminate\Console\Command;
use Nexzan\Shared\Models\DurableOperation;

class OperationsReviewCommand extends Command
{
    protected $signature = 'operations:review {id} {--retry : Retry after checking the remote outcome} {--complete : Confirm the remote effect already succeeded} {--inspected : Confirm the worker is stopped and remote outcome was inspected}';

    protected $description = 'Inspect or explicitly resolve a failed/interrupted external operation';

    public function handle(): int
    {
        $operation = DurableOperation::query()->findOrFail($this->argument('id'));
        $this->line(json_encode($operation->only(['id', 'event_id', 'operation_key', 'status', 'attempts', 'started_at', 'last_error']), JSON_PRETTY_PRINT));
        if (! $this->option('retry') && ! $this->option('complete')) {
            return self::SUCCESS;
        }
        if (! $this->option('inspected') || ($this->option('retry') && $this->option('complete'))) {
            $this->error('Choose one resolution and pass --inspected after stopping the worker and inspecting the remote outcome.');

            return self::FAILURE;
        }
        $updated = DurableOperation::query()->whereKey($operation->id)
            ->whereIn('status', ['processing', 'needs_review'])->update([
                'status' => $this->option('retry') ? 'pending' : 'completed',
                'started_at' => null,
                'completed_at' => $this->option('complete') ? now() : null,
            ]);

        return $updated ? self::SUCCESS : self::FAILURE;
    }
}
