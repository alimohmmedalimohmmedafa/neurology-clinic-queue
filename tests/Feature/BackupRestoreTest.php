<?php

namespace Tests\Feature;

use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    private string $directory;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        // A real file database (not :memory:) so VACUUM INTO and the
        // restore copy have something on disk to work with.
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'queue-backup-'.uniqid();
        mkdir($this->directory, 0777, true);
        $this->database = $this->directory.DIRECTORY_SEPARATOR.'database.sqlite';
        touch($this->database);

        config(['database.connections.sqlite.database' => $this->database]);
        DB::purge('sqlite');

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        $this->deleteDirectory($this->directory);

        parent::tearDown();
    }

    // ---- 11. data persists across an application restart ----------------

    public function test_scenario_data_persists_across_application_restart(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->postJson('/api/queue/visits/1/move-to-review')->assertOk();

        // Simulate stopping and starting the application: drop the open
        // connection and read again purely from the file on disk.
        DB::purge('sqlite');

        $this->assertSame(1, Visit::count());
        $this->assertDatabaseHas('visits', ['id' => 1, 'queue_number' => 1, 'stage' => 'review', 'status' => 'waiting']);
        $this->assertDatabaseHas('queue_events', ['visit_id' => 1, 'event' => 'moved_to_review']);
        $this->getJson('/api/queue/state')->assertJsonPath('stages.review.waiting.0.queue_number', '001');
    }

    // ---- backup / restore round trip -----------------------------------

    public function test_scenario_backup_and_restore_round_trip(): void
    {
        $backup = $this->directory.DIRECTORY_SEPARATOR.'backup.sqlite';

        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->assertSame(2, Visit::count());

        $this->artisan('queue:backup', ['--path' => $backup])->assertSuccessful();
        $this->assertFileExists($backup);

        Visit::query()->delete();
        $this->assertSame(0, Visit::count());

        DB::purge('sqlite');

        $this->artisan('queue:restore', ['file' => $backup, '--force' => true])->assertSuccessful();

        $this->assertSame(2, Visit::count());
        $this->assertDatabaseHas('visits', ['id' => 1, 'queue_number' => 1]);
        $this->assertDatabaseHas('visits', ['id' => 2, 'queue_number' => 2]);
    }

    public function test_backup_command_fails_when_database_file_is_missing(): void
    {
        config(['database.connections.sqlite.database' => $this->directory.'/missing.sqlite']);
        DB::purge('sqlite');

        $this->artisan('queue:backup')->assertFailed();
    }

    public function test_restore_command_rejects_missing_or_invalid_backup(): void
    {
        $this->artisan('queue:restore', ['file' => $this->directory.'/nope.sqlite', '--force' => true])
            ->assertFailed();

        $corrupt = $this->directory.DIRECTORY_SEPARATOR.'corrupt.sqlite';
        file_put_contents($corrupt, 'this is not a sqlite database');

        $this->artisan('queue:restore', ['file' => $corrupt, '--force' => true])
            ->assertFailed();
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
