<?php

namespace Nexzan\Shared\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class MigrationFresh extends Command
{
    protected $signature = 'migrate:fresh-custom';

    protected $description = 'Fresh all migrations including shared_db tables and reseed the database';

    public function dropCoreServiceTables(): void
    {
        $migrationPath = database_path('migrations/shared_db');
        $files = File::files($migrationPath);
        $tables = [];

        Schema::connection('shared_db')->disableForeignKeyConstraints();

        foreach ($files as $file) {
            $content = File::get($file->getPathname());

            if (preg_match_all("/Schema::create\s*\(\s*['\"](.*?)['\"]/", $content, $matches)) {
                foreach ($matches[1] as $table) {
                    $tables[] = $table;
                }
            }

            // ✅ Safely delete from migrations table
            $migrationName = pathinfo($file->getFilename(), PATHINFO_FILENAME);

            DB::connection('shared_db')->table("migrations")
                ->where('migration', $migrationName)
                ->delete();
        }

        $tables = array_unique($tables);

        foreach ($tables as $table) {
            if (Schema::connection('shared_db')->hasTable($table)) {
                Schema::connection('shared_db')->drop($table);
                Log::debug("Dropped: $table");
                $this->info("Dropped: $table");
            } else {
                $this->warn("Not found: $table");
            }
        }

        Schema::connection('shared_db')->enableForeignKeyConstraints();
    }



    public function handle()
    {

        $this->dropCoreServiceTables();

        $this->call('migrate', [
            '--database' => 'shared_db',
            '--path' => 'database/migrations/shared_db',
            '--force' => true,
        ]);
        
        $this->call('migrate:fresh', [
            '--seed' => true,
        ]);

        
    }
}
