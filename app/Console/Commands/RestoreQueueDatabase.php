<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;

class RestoreQueueDatabase extends Command
{
    protected $signature = 'queue:restore {file : Path to the backup file to restore} {--force : Skip the confirmation prompt}';

    protected $description = 'Restore the queue SQLite database from a backup created by queue:backup';

    public function handle(): int
    {
        $backup = (string) $this->argument('file');
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! is_file($backup)) {
            $this->error("Backup file not found: {$backup}");

            return self::FAILURE;
        }

        if (! $this->backupIntegrityOk($backup)) {
            $this->error('Backup failed the integrity check and will not be restored.');

            return self::FAILURE;
        }

        $this->warn("Restoring will OVERWRITE the current database: {$database}");
        $this->line('Stop the web server (php artisan serve) before restoring, then start it again.');

        if (! $this->option('force') && ! $this->confirm('Continue with the restore?')) {
            $this->line('Restore cancelled.');

            return self::SUCCESS;
        }

        if (! copy($backup, $database)) {
            $this->error("Could not write to: {$database}");

            return self::FAILURE;
        }

        // Remove stale WAL sidecars so the restored file starts clean.
        foreach (['-wal', '-shm'] as $suffix) {
            $sidecar = $database.$suffix;

            if (is_file($sidecar)) {
                unlink($sidecar);
            }
        }

        $this->info("Database restored from: {$backup}");

        return self::SUCCESS;
    }

    private function backupIntegrityOk(string $backup): bool
    {
        try {
            $pdo = new PDO('sqlite:'.$backup, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $result = $pdo->query('PRAGMA integrity_check')->fetchColumn();

            if ($result !== 'ok') {
                return false;
            }

            $tables = $pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('visits', 'queue_events', 'migrations')"
            )->fetchColumn();

            return (int) $tables === 3;
        } catch (\Throwable) {
            return false;
        }
    }
}
