<?php

namespace Nexzan\Shared\Console\Commands;

use Illuminate\Console\Command;

class MigrationRollback extends Command
{
    protected $signature = 'migrate:custom-rollback';

    protected $description = 'Rollback the last batch of migrations from both the default and shared_db databases';

    public function handle()
    {
        // Rollback default database
        $this->call('migrate:rollback');

        // Rollback shared_db migrations
        $this->call('migrate:rollback', [
            '--database' => 'shared_db',
            '--path' => 'database/migrations/shared_db',
            '--force' => true,
        ]);
    }
}
