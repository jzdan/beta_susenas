<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Services\RuleEngine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Inc 164 — kosongkan SEMUA aturan validasi (semua versi kuesioner) beserta hasil, riwayat, dan
 * tanda "sudah dikonfirmasi" peringatannya; lalu hitung ulang tiap isian supaya ringkasan &
 * tabulasi tidak memuat sisa hasil aturan lama. Blok, rincian, parameter, agregat, turunan,
 * dan patokan Harga & Kalori TIDAK disentuh.
 *
 * Dipanggil DatabaseSeeder bila .env berisi SEED_ATURAN=false. Manual (database yang sudah berisi):
 *   php artisan db:seed --class=Database\\Seeders\\KosongkanAturanSeeder
 *
 * Lewat query builder (bukan model) — versi milik siklus 'selesai' menolak perubahan lewat model.
 */
class KosongkanAturanSeeder extends Seeder
{
    public function run(): void
    {
        $n = DB::table('validation_rules')->count();
        DB::transaction(function () {
            DB::table('rule_results')->delete();
            DB::table('warning_acks')->delete();
            DB::table('rule_revisions')->delete();
            DB::table('validation_rules')->delete();
        });
        $engine = app(RuleEngine::class);
        foreach (Document::orderBy('id')->cursor() as $doc) {
            $engine->run($doc);
        }
        DB::table('questionnaire_versions')->update(['rules_changed_at' => null]);
        $this->command?->info("Aturan validasi dikosongkan ({$n} aturan dihapus).");
    }
}
