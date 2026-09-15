<?php

namespace Nexzan\Shared\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MessagingHealthCommand extends Command
{
    protected $signature = 'messaging:health {--max-age=300 : Maximum unresolved age in seconds}';

    protected $description = 'Print messaging backlog metrics as JSON; fail on dead, stalled or review-required work';

    public function handle(): int
    {
        $healthy = true;
        $metrics = [];
        foreach (['outbox_events' => 'published', 'inbox_events' => 'completed', 'durable_operations' => 'completed'] as $table => $done) {
            $counts = DB::table($table)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
            $oldest = DB::table($table)->where('status', '!=', $done)->min('created_at');
            $age = $oldest ? max(0, now()->timestamp - strtotime($oldest)) : 0;
            $metrics[$table] = ['counts' => $counts, 'oldest_unresolved_seconds' => $age];
            if ($age > max(1, (int) $this->option('max-age')) || ($counts['dead'] ?? 0) > 0 || ($counts['needs_review'] ?? 0) > 0) {
                $healthy = false;
            }
        }
        $this->line(json_encode(['healthy' => $healthy, 'metrics' => $metrics], JSON_PRETTY_PRINT));

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
