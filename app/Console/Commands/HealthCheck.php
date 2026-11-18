<?php

namespace App\Console\Commands;

use App\Models\Aggregate;
use App\Models\Block;
use App\Models\Document;
use App\Models\DocumentSnapshot;
use App\Models\QuestionnaireVersion;
use App\Models\RuleResult;
use App\Models\SampleHousehold;
use App\Models\User;
use App\Models\ValidationRule;
use App\Services\ExpressionEvaluator;
use App\Services\RuleEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HealthCheck extends Command
{
    protected $signature = 'susenas:health';

    protected $description = 'Ringkasan kondisi data & deteksi masalah umum';

    public function handle(ExpressionEvaluator $ev): int
    {
        $problems = 0;

        $this->line('<info>Ringkasan</info>');
        $this->table(['Entitas', 'Jumlah'], [
            ['Versi kuesioner', QuestionnaireVersion::count()],
            ['Blok', Block::count()],
            ['Aturan validasi (aktif)', ValidationRule::where('active', true)->count()],
            ['Dokumen', Document::count()],
            ['Snapshot dokumen', DocumentSnapshot::count()],
            ['Hasil aturan (error)', RuleResult::where('status', 'error')->count()],
            ['Hasil aturan (warning)', RuleResult::where('status', 'warning')->count()],
        ]);

        // 1. dokumen tanpa snapshot (mesin belum pernah jalan)
        $noSnap = Document::whereNotIn('id', DocumentSnapshot::select('document_id'))->count();
        if ($noSnap > 0) {
            $this->warn("• {$noSnap} dokumen belum punya snapshot — jalankan RuleEngine (buka dokumen / revalidasi).");
            $problems++;
        }

        // 2. aturan aktif dengan sintaks ekspresi rusak
        $funcNames = RuleEngine::ROSTER_FUNCS;
        $broken = [];
        foreach (ValidationRule::where('active', true)->get() as $rule) {
            $err = $ev->checkSyntax($rule->expr, $funcNames);
            if ($err !== '') {
                $broken[] = [$rule->code, $err];
            }
        }
        if ($broken) {
            $this->error('• Aturan dengan sintaks ekspresi rusak:');
            $this->table(['Kode', 'Pesan'], $broken);
            $problems += count($broken);
        }

        // 3. versi kuesioner tanpa blok
        $emptyVersions = QuestionnaireVersion::whereNotIn('id', Block::select('qversion_id'))->pluck('code');
        if ($emptyVersions->isNotEmpty()) {
            $this->warn('• Versi kuesioner tanpa blok: '.$emptyVersions->implode(', '));
            $problems++;
        }

        // 4. aturan per-baris menunjuk blok yang tidak ada di versinya
        $blockCodes = [];
        foreach (Block::select('qversion_id', 'code')->get() as $b) {
            $blockCodes[$b->qversion_id][$b->code] = true;
        }
        $badRowBlock = [];
        foreach (ValidationRule::where('row_block', '!=', '')->get() as $r) {
            if (! isset($blockCodes[$r->qversion_id][$r->row_block])) {
                $badRowBlock[] = [$r->code, $r->row_block];
            }
        }
        if ($badRowBlock) {
            $this->error('• Aturan per-baris menunjuk blok yang tidak ada:');
            $this->table(['Kode', 'row_block'], $badRowBlock);
            $problems += count($badRowBlock);
        }

        // 5. agregat menunjuk block_code yang tidak ada
        $badAgg = [];
        foreach (Aggregate::where('block_code', '!=', '')->get() as $a) {
            if (! isset($blockCodes[$a->qversion_id][$a->block_code])) {
                $badAgg[] = [$a->name, $a->block_code];
            }
        }
        if ($badAgg) {
            $this->error('• Agregat menunjuk block_code yang tidak ada:');
            $this->table(['Nama', 'block_code'], $badAgg);
            $problems += count($badAgg);
        }

        // 6. dokumen 'bersih' tapi hasil aturan terakhir masih ada error
        $dirtyClean = Document::where('status', 'bersih')
            ->whereIn('id', RuleResult::where('status', 'error')->select('document_id'))
            ->pluck('id');
        if ($dirtyClean->isNotEmpty()) {
            $this->error('• Dokumen berstatus bersih tapi masih ada error: #'.$dirtyClean->implode(', #')
                .' — buka kunci & perbaiki, atau revalidasi.');
            $problems += $dirtyClean->count();
        }

        // 7. sampel di siklus lapangan tanpa petugas
        $unassigned = SampleHousehold::query()
            ->join('survey_cycles as c', 'c.id', '=', 'sample_households.cycle_id')
            ->where('c.status', 'lapangan')
            ->whereNull('sample_households.petugas_id')
            ->whereNotIn('sample_households.status', ['ganti_sampel'])
            ->count();
        if ($unassigned > 0) {
            $this->warn("• {$unassigned} sampel di siklus berstatus lapangan belum punya petugas.");
            $problems++;
        }

        // 8. jawaban yatim (question_id tidak ada) — sisa migrasi/hapus rincian
        $orphanAns = DB::table('answers')
            ->whereNotIn('question_id', DB::table('questions')->select('id'))->count();
        if ($orphanAns > 0) {
            $this->warn("• {$orphanAns} baris jawaban menunjuk rincian yang sudah dihapus.");
            $problems++;
        }

        // 9. sampel 'proses'/'selesai' tanpa dokumen — status tak konsisten
        $noDoc = SampleHousehold::whereIn('status', ['proses', 'selesai'])
            ->whereNotIn('id', DB::table('documents')->select('sample_id'))->count();
        if ($noDoc > 0) {
            $this->warn("• {$noDoc} sampel berstatus proses/selesai tapi belum punya dokumen.");
            $problems++;
        }

        // 10. lebih dari satu PJ aktif untuk kecamatan yang sama
        $dupePj = User::where('role', 'pj')->where('active', true)
            ->select('wilayah_scope')->groupBy('wilayah_scope')
            ->havingRaw('count(*) > 1')->pluck('wilayah_scope');
        if ($dupePj->isNotEmpty()) {
            $this->error('• Lebih dari satu PJ aktif untuk kecamatan: '.$dupePj->implode(', '));
            $problems += $dupePj->count();
        }

        // 11. keutuhan versi aktif (Inc 145): aturan/agregat/turunan/parameter yang putus
        if ($active = QuestionnaireVersion::where('is_active', true)->first()) {
            $vc = app(\App\Services\VersionChecker::class)->check($active);
            if ($vc['errors']) {
                $this->error("• Versi aktif {$active->code} punya rujukan putus:");
                foreach ($vc['errors'] as $e) {
                    $this->line("    - {$e}");
                }
                $problems += count($vc['errors']);
            }
        }

        if ($problems === 0) {
            $this->info('OK — tidak ada masalah terdeteksi.');
            return self::SUCCESS;
        }
        $this->newLine();
        $this->error("{$problems} masalah terdeteksi.");
        return self::FAILURE;
    }
}
