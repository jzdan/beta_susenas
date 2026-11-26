<?php

namespace Database\Seeders;

use App\Models\Wilayah;
use Illuminate\Database\Seeder;

/**
 * Inc 165 — master wilayah Kabupaten Bangkalan: 18 kecamatan + 281 desa/kelurahan dari
 * database/data/wilayah_bangkalan.csv (format Impor Wilayah: code,name,level; disusun oleh
 * scripts/build_wilayah_bangkalan.php dari GADM 4.1).
 *
 * Kode kecamatan = kode statistik BPS. Kode desa SEMENTARA (urut abjad per kecamatan) — timpa
 * dengan master wilayah BPS resmi lewat Admin → Wilayah → Impor bila sudah tersedia.
 * Kode yang sudah ada diperbarui namanya (bukan diduplikasi). Dipanggil DatabaseSeeder di luar tes.
 */
class WilayahBangkalanSeeder extends Seeder
{
    public const CSV = 'data/wilayah_bangkalan.csv';

    public function run(): void
    {
        $path = database_path(self::CSV);
        if (! is_file($path)) {
            $this->command?->warn('Berkas '.self::CSV.' tidak ada — wilayah tidak dimuat.');

            return;
        }
        $fh = fopen($path, 'r');
        $head = fgetcsv($fh, null, ',', '"', '');
        $parentLen = ['kabupaten' => 0, 'kecamatan' => 4, 'desa' => 7];
        $n = 0;
        while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
            $r = array_combine($head, $row);
            Wilayah::updateOrCreate(['code' => $r['code']], [
                'name' => $r['name'], 'level' => $r['level'],
                'parent_code' => $parentLen[$r['level']] ? substr($r['code'], 0, $parentLen[$r['level']]) : '',
            ]);
            $n++;
        }
        fclose($fh);
        $this->command?->info("Wilayah Bangkalan: {$n} baris.");
    }
}
