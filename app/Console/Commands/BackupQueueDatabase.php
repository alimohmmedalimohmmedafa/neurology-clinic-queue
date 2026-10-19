<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackupQueueDatabase extends Command
{
    protected $signature = 'queue:backup {--path= : Full path for the backup file}';

    protected $description = 'Create a consistent online backup of the queue SQLite database using VACUUM INTO';

    public function handle(): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! is_file($database)) {
            $this->error("Database file not found: {$database}");

            return self::FAILURE;
        }

        $destination = $this->option('path')
            ?: storage_path('app/backups/queue-'.now()->format('Y-m-d_His').'.sqlite');

        $directory = dirname($destination);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error("Could not create backup directory: {$directory}");

            return self::FAILURE;
        }

        if (is_file($destination)) {
            $this->error("Backup file already exists: {$destination}");

            return self::FAILURE;
        }

        // VACUUM INTO takes a transactionally consistent snapshot while the
        // app keeps serving reads and writes — no unsafe file copying.
        DB::unprepared('VACUUM INTO '.DB::getPdo()->quote($destination));

        $this->info("Backup created: {$destination} (".number_format(filesize($destination)).' bytes)');
        $this->line('To restore: stop the web server, then run:');
        $this->line("  php artisan queue:restore \"{$destination}\"");

        return self::SUCCESS;
    }
}
