<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Backup extends Command
{
    protected $signature = 'susenas:backup
        {--keep=14 : jumlah cadangan terbaru yang disimpan}
        {--dir= : folder tujuan}
        {--source= : berkas SQLite sumber (default: basis data aktif)}';

    protected $description = 'Salin basis data ke folder cadangan (SQLite) + pangkas yang lama';

    public function handle(): int
    {
        $explicit = (string) ($this->option('source') ?? '');
        if ($explicit === '') {
            $driver = config('database.connections.'.config('database.default').'.driver');
            if ($driver !== 'sqlite') {
                $this->error("Driver '{$driver}' tidak didukung perintah ini. Gunakan pg_dump / mysqldump di skrip cron.");

                return self::FAILURE;
            }
            $src = config('database.connections.'.config('database.default').'.database');
        } else {
            $src = $explicit;
        }

        if (! is_file($src)) {
            $this->error("Berkas basis data tidak ditemukan: {$src}");

            return self::FAILURE;
        }

        $dir = $this->option('dir') ?: storage_path('app/backups');
        @mkdir($dir, 0775, true);
        $dest = rtrim($dir, '/\\').'/susenas-'.now()->format('Ymd-His').'.sqlite';

        if ($explicit === '') {
            try {
                DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
            } catch (\Throwable) {
                // abaikan bila bukan mode WAL
            }
        }

        if (! @copy($src, $dest)) {
            $this->error('Gagal menyalin basis data.');

            return self::FAILURE;
        }
        $this->info('Cadangan: '.$dest.' ('.number_format(filesize($dest) / 1024, 0).' KB)');

        // modul bukti kunjungan (opsional): arsipkan folder foto sebagai zip
        $fotoDir = storage_path('app/kunjungan');
        if (config('susenas.bukti_kunjungan') && is_dir($fotoDir) && class_exists(\ZipArchive::class)) {
            $zipDest = preg_replace('/\.sqlite$/', '-foto.zip', $dest);
            $zip = new \ZipArchive;
            if ($zip->open($zipDest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                $n = 0;
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fotoDir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                    if ($f->isFile()) {
                        $zip->addFile($f->getPathname(), 'kunjungan/'.substr($f->getPathname(), strlen($fotoDir) + 1));
                        $n++;
                    }
                }
                $zip->close();
                $this->info("Foto bukti kunjungan: {$zipDest} ({$n} berkas)");
            }
        }

        $keep = max(1, (int) $this->option('keep'));
        $files = glob($dir.'/susenas-*.sqlite') ?: [];
        rsort($files);
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
            @unlink(preg_replace('/\.sqlite$/', '-foto.zip', $old));
            $this->line('  dihapus (lama): '.basename($old));
        }

        return self::SUCCESS;
    }
}
