<?php

namespace Nexzan\Shared\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nexzan\Shared\Infrastructure\ResourceDeletionGuard;

class PurgeDeletedResourcesCommand extends Command
{
    protected $signature = 'resources:purge-deleted {--batch=500} {--dry-run}';

    protected $description = 'Permanently delete resource rows soft-deleted at least six calendar months ago';

    public function handle(ResourceDeletionGuard $guard): int
    {
        $cutoff = now()->subMonthsNoOverflow(6);
        $batch = max(1, (int) $this->option('batch'));
        $total = 0;

        // Configuration lists children before parents to respect foreign keys.
        foreach (config('rabbitmq.retained_resources', []) as $type => $resource) {
            /** @var Model $model */
            $model = new $resource['model'];
            $query = $model->newQueryWithoutScopes()->whereNotNull('deleted_at')->where('deleted_at', '<=', $cutoff);
            if ($this->option('dry-run')) {
                $count = $query->count();
            } else {
                $count = 0;
                $query->select($model->getKeyName())->chunkById($batch, function ($rows) use ($model, $type, $cutoff, $guard, &$count): void {
                    foreach ($rows as $row) {
                        $count += DB::transaction(function () use ($model, $type, $row, $cutoff, $guard): int {
                            $guard->lock($type, (string) $row->getKey());

                            // Bypass model observers: provider cleanup and domain
                            // events already happened at logical deletion time.
                            return $model->getConnection()->table($model->getTable())
                                ->where($model->getKeyName(), $row->getKey())
                                ->whereNotNull('deleted_at')->where('deleted_at', '<=', $cutoff)->delete();
                        }, 3);
                    }
                }, $model->getKeyName());
            }
            $total += $count;
            $this->line($type.': '.$count);
        }
        $this->info(($this->option('dry-run') ? 'Eligible' : 'Purged')." resource rows: {$total}");

        return self::SUCCESS;
    }
}
