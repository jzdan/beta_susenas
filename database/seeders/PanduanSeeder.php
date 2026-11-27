<?php

namespace Database\Seeders;

use App\Models\GuideSlide;
use App\Support\Panduan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Inc 167 — isi awal panduan bergambar dari database/data/panduan/panduan.json (+ gambar di
 * database/data/panduan/img/, dibuat scripts/panduan_gambar.py). Hanya mengisi halaman yang belum
 * punya langkah — panduan yang sudah diubah admin tidak ditimpa. Dipanggil DatabaseSeeder & MetadataSeeder.
 *   php artisan db:seed --class=Database\\Seeders\\PanduanSeeder
 */
class PanduanSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/panduan/panduan.json');
        if (! is_file($path)) {
            return;
        }
        $data = json_decode(file_get_contents($path), true) ?: [];
        $made = 0;
        foreach ($data as $key => $slides) {
            if (str_starts_with($key, '_') || ! Panduan::exists($key) || GuideSlide::where('page_key', $key)->exists()) {
                continue;
            }
            foreach (array_values($slides) as $i => $s) {
                $img = null;
                $src = database_path('data/panduan/img/'.($s['image'] ?? ''));
                if (! empty($s['image']) && is_file($src)) {
                    $img = 'panduan/'.$s['image'];
                    Storage::disk('local')->put($img, file_get_contents($src));
                }
                GuideSlide::create(['page_key' => $key, 'order' => $i + 1, 'title' => $s['title'],
                    'section' => $s['section'] ?? null, 'link' => $s['link'] ?? null,
                    'body' => $s['body'] ?? null, 'image_path' => $img]);
                $made++;
            }
        }
        $this->command?->info("Panduan: {$made} langkah dibuat.");
    }
}
