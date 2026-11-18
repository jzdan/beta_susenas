<?php

namespace Database\Seeders;

use App\Models\Aggregate;
use App\Models\Block;
use App\Models\BlockRow;
use App\Models\DerivedField;
use App\Models\Parameter;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\ResponseOption;
use App\Models\SurveyCycle;
use App\Models\ValidationRule;
use Illuminate\Database\Seeder;

/**
 * Kerangka siklus & instrumen Susenas + Seruti 2026 untuk 4 triwulan, mengikuti
 * penjelasan user: "1 siklus per instrumen", BUKAN "1 siklus per triwulan" —
 * karena sistem ini mengasumsikan 1 siklus = 1 kuesioner (dokumen baru memakai
 * kuesioner terakhir di cycle_id sampel itu). Kalau satu siklus berisi >1
 * kuesioner, hanya kuesioner ber-id terbesar yang kepakai — salah.
 *
 * TIDAK dijalankan otomatis oleh DatabaseSeeder (supaya dataset demo/tes utama
 * tidak berubah) — jalankan manual:
 *   php artisan db:seed --class=Database\\Seeders\\Susenas2026CycleSeeder
 *
 * Yang SUDAH ada isinya (dipakai apa adanya, tidak disentuh):
 *   - VSEN26.K     — SUSENAS Maret 2026 (KOR), dari DatabaseSeeder::korQuestionnaire()
 *   - VSEN26.KP    — SUSENAS Maret 2026 (KP), dari DatabaseSeeder::kpFullQuestionnaire()
 *   - VSERUTI26.INTI — SERUTI TW I 2026, dari SerutiSeeder (dipanggil di sini bila belum ada)
 *
 * Yang dibuat DI SINI:
 *   - VSEN26.M         — SUSENAS September (Modul) — PLACEHOLDER KOSONG, isi belum ada.
 *   - VSEN26.KP-TW3    — SUSENAS September (KP) — duplikat struktur dari VSEN26.KP Maret
 *                        (asumsi awal: sama sampai ada revisi nyata dari BPS).
 *   - VSERUTI26.INTI-TW2/-TW3/-TW4 — duplikat struktur dari VSERUTI26.INTI TW I.
 *   - VSERUTI26.MAK-TW2/-TW4 — PLACEHOLDER KOSONG, isi belum ada.
 * (VSERUTI26.INTI TW III memang duplikat, bukan "reuse" September KP — Seruti &
 * Susenas tetap dua instrumen terpisah walau dilaksanakan bulan yang sama.)
 *
 * "Placeholder kosong" = qversion tanpa blok. Ini SENGAJA — muncul sebagai
 * warning di `php artisan susenas:health` ("versi kuesioner tanpa blok") agar
 * jadi pengingat nyata, bukan konten karangan.
 */
class Susenas2026CycleSeeder extends Seeder
{
    public function run(): void
    {
        if (SurveyCycle::where('name', 'SUSENAS September 2026 (KP)')->exists()) {
            return; // sudah pernah dijalankan
        }

        // pastikan VSERUTI26.INTI TW I ada (idempoten - no-op kalau sudah ada)
        $this->call(SerutiSeeder::class);

        $kp1 = QuestionnaireVersion::where('code', 'VSEN26.KP')->first();
        $inti1 = QuestionnaireVersion::where('code', 'VSERUTI26.INTI')->first();
        if (! $kp1 || ! $inti1) {
            return; // prasyarat belum ada (mis. iv1_komoditas.csv / seruti_iv2a.csv hilang)
        }

        // ---------- TW II / Juni ----------
        $juniInti = SurveyCycle::create(['name' => 'SERUTI Juni 2026 (INTI)', 'period' => '2026-06',
            'status' => 'pengolahan', 'field_start' => '2026-06-01', 'field_end' => '2026-06-30']);
        $this->duplicateInto($inti1, $juniInti->id, 'VSERUTI26.INTI-TW2', '2026-05-05');

        $juniMak = SurveyCycle::create(['name' => 'SERUTI Juni 2026 (MAK)', 'period' => '2026-06',
            'status' => 'pengolahan', 'field_start' => '2026-06-01', 'field_end' => '2026-06-30']);
        QuestionnaireVersion::create(['cycle_id' => $juniMak->id, 'code' => 'VSERUTI26.MAK-TW2',
            'effective_date' => '2026-05-05']);

        // ---------- TW III / September ----------
        $sepModul = SurveyCycle::create(['name' => 'SUSENAS September 2026 (Modul)', 'period' => '2026-09',
            'status' => 'lapangan', 'field_start' => '2026-09-01', 'field_end' => '2026-09-30']);
        QuestionnaireVersion::create(['cycle_id' => $sepModul->id, 'code' => 'VSEN26.M',
            'effective_date' => '2026-08-05']);

        $sepKp = SurveyCycle::create(['name' => 'SUSENAS September 2026 (KP)', 'period' => '2026-09',
            'status' => 'lapangan', 'field_start' => '2026-09-01', 'field_end' => '2026-09-30']);
        $this->duplicateInto($kp1, $sepKp->id, 'VSEN26.KP-TW3', '2026-08-05');

        $sepInti = SurveyCycle::create(['name' => 'SERUTI September 2026 (INTI)', 'period' => '2026-09',
            'status' => 'lapangan', 'field_start' => '2026-09-01', 'field_end' => '2026-09-30']);
        $this->duplicateInto($inti1, $sepInti->id, 'VSERUTI26.INTI-TW3', '2026-08-05');

        // ---------- TW IV / November ----------
        $novInti = SurveyCycle::create(['name' => 'SERUTI November 2026 (INTI)', 'period' => '2026-11',
            'status' => 'persiapan', 'field_start' => '2026-11-01', 'field_end' => '2026-11-30']);
        $this->duplicateInto($inti1, $novInti->id, 'VSERUTI26.INTI-TW4', '2026-10-05');

        $novMak = SurveyCycle::create(['name' => 'SERUTI November 2026 (MAK)', 'period' => '2026-11',
            'status' => 'persiapan', 'field_start' => '2026-11-01', 'field_end' => '2026-11-30']);
        QuestionnaireVersion::create(['cycle_id' => $novMak->id, 'code' => 'VSERUTI26.MAK-TW4',
            'effective_date' => '2026-10-05']);
    }

    /**
     * Salin struktur (blok/rincian/pilihan/baris/parameter/turunan/agregat/aturan)
     * dari satu qversion ke qversion baru di SIKLUS lain. Sama seperti
     * AdminController::duplicateVersion(), tapi lintas-siklus (bukan lintas-versi
     * di siklus yang sama) — dipakai untuk instrumen yang isinya diasumsikan sama
     * sampai ada revisi nyata dari BPS.
     */
    private function duplicateInto(QuestionnaireVersion $src, int $cycleId, string $newCode, string $effectiveDate): QuestionnaireVersion
    {
        $dst = QuestionnaireVersion::create([
            'cycle_id' => $cycleId, 'code' => $newCode, 'effective_date' => $effectiveDate,
        ]);
        foreach ($src->blocks as $b) {
            $nb = Block::create(['qversion_id' => $dst->id, 'code' => $b->code, 'title' => $b->title,
                'order' => $b->order, 'repeat' => $b->repeat, 'harga_kalori' => $b->harga_kalori]);
            foreach ($b->questions as $q) {
                $nq = Question::create([
                    'block_id' => $nb->id, 'code_rincian' => $q->code_rincian, 'var_name' => $q->var_name,
                    'label' => $q->label, 'qtype' => $q->qtype, 'unit' => $q->unit, 'order' => $q->order,
                    'skip_expr' => $q->skip_expr, 'help_text' => $q->help_text,
                ]);
                foreach ($q->options as $o) {
                    ResponseOption::create(['question_id' => $nq->id, 'code' => $o->code,
                        'label' => $o->label, 'order' => $o->order]);
                }
            }
            foreach ($b->rows as $row) {
                BlockRow::create(['block_id' => $nb->id, 'row_index' => $row->row_index,
                    'code' => $row->code, 'label' => $row->label, 'order' => $row->order, 'ref' => $row->ref]);
            }
        }
        foreach ($src->parameters as $p) {
            Parameter::create(['qversion_id' => $dst->id, 'name' => $p->name, 'value' => $p->value, 'note' => $p->note]);
        }
        foreach ($src->derived as $x) {
            DerivedField::create(['qversion_id' => $dst->id, 'var_name' => $x->var_name,
                'label' => $x->label, 'expr' => $x->expr, 'order' => $x->order]);
        }
        foreach ($src->aggregates as $a) {
            Aggregate::create(array_merge(
                $a->only((new Aggregate)->getFillable()),
                ['qversion_id' => $dst->id],
            ));
        }
        foreach ($src->rules as $r) {
            ValidationRule::create(array_merge(
                $r->only((new ValidationRule)->getFillable()),
                ['qversion_id' => $dst->id],
            ));
        }

        return $dst;
    }
}
