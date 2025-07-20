<?php

namespace Nexzan\Shared\Console\Commands;

use Illuminate\Console\Command;

class Migration extends Command
{
    protected $signature = 'migrate:custom';

    protected $description = 'Run default migrations and then run shared_db migrations from the specified path';

    public function handle()
    {

        $this->call('migrate', [
            '--database' => 'shared_db',
            '--path' => 'database/migrations/shared_db',
            '--force' => true,
        ]);

        $this->call('migrate');

    }
}
