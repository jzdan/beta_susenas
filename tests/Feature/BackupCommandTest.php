<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackupCommandTest extends TestCase
{
    public function test_backup_copies_sqlite_and_prunes_old_files(): void
    {
        $dir = storage_path('app/backups-test-'.uniqid());
        @mkdir($dir, 0775, true);
        $srcDb = $dir.'/source.sqlite';
        file_put_contents($srcDb, "SQLite format 3\0");

        // lima cadangan "lama" dengan cap waktu berbeda
        foreach (range(1, 5) as $d) {
            touch($dir.'/susenas-'.now()->subDays($d)->format('Ymd-His').'.sqlite');
        }

        $this->artisan('susenas:backup', ['--dir' => $dir, '--keep' => 2, '--source' => $srcDb])
            ->assertExitCode(0);

        $files = glob($dir.'/susenas-*.sqlite');
        $this->assertCount(2, $files, 'hanya --keep terbaru (termasuk cadangan baru) yang tersisa');

        array_map('unlink', array_merge($files, [$srcDb]));
        @rmdir($dir);
    }

    public function test_backup_rejects_non_sqlite_driver(): void
    {
        config()->set('database.default', 'mysql');
        $this->artisan('susenas:backup')
            ->expectsOutputToContain('tidak didukung')
            ->assertExitCode(1);
    }
}
