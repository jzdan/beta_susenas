<?php

namespace Database\Seeders;

use App\Models\Aggregate;
use App\Models\Answer;
use App\Models\Block;
use App\Models\BlockRow;
use App\Models\Document;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\SampleHousehold;
use App\Models\SurveyCycle;
use App\Models\User;
use App\Models\ValidationRule;
use App\Models\Wilayah;
use App\Services\RuleEngine;
use Illuminate\Database\Seeder;

/**
 * Modul SERUTI (Survei Konsumsi & Investasi Rumah Tangga) — kuesioner
 * VSERUTI26.INTI. Slice pertama: Blok IV.2.A (pengeluaran bukan makanan
 * sebulan & tiga bulan terakhir), 52 rincian dari WTF Susenas Seruti Maret 2026.
 * Kuesioner adalah DATA — lihat database/data/seruti_iv2a.csv.
 */
class SerutiSeeder extends Seeder
{
    public function run(): void
    {
        $csv = database_path('data/seruti_iv2a.csv');
        if (! is_file($csv) || QuestionnaireVersion::where('code', 'VSERUTI26.INTI')->exists()) {
            return;
        }

        $cycle = SurveyCycle::create([
            'name' => 'SERUTI Maret 2026', 'period' => '2026-03', 'status' => 'pengolahan',
            'field_start' => '2026-03-01', 'field_end' => '2026-04-15',
        ]);
        $qv = QuestionnaireVersion::create([
            'cycle_id' => $cycle->id, 'code' => 'VSERUTI26.INTI', 'effective_date' => '2025-11-05',
        ]);

        // Blok Pokok ringkas
        $bp = Block::create(['qversion_id' => $qv->id, 'code' => 'POKOK',
            'title' => 'Keterangan Pokok', 'order' => 0, 'repeat' => 'ruta']);
        Question::create(['block_id' => $bp->id, 'code_rincian' => 'R301', 'var_name' => 'banyak_art',
            'label' => 'Banyaknya anggota rumah tangga', 'qtype' => 'int', 'unit' => 'orang', 'order' => 1]);

        // Blok IV.2.A — daftar rincian pengeluaran bukan makanan
        $b = Block::create(['qversion_id' => $qv->id, 'code' => 'IV2A',
            'title' => 'Pengeluaran Bukan Makanan Sebulan & Tiga Bulan Terakhir', 'order' => 1, 'repeat' => 'list']);

        $fh = fopen($csv, 'r');
        $head = array_map('trim', fgetcsv($fh));
        while (($cells = fgetcsv($fh)) !== false) {
            $row = array_combine($head, array_pad($cells, count($head), ''));
            if (($row['urut'] ?? '') === '') {
                continue;
            }
            BlockRow::create([
                'block_id' => $b->id, 'row_index' => (int) $row['urut'],
                'code' => $row['kode'], 'label' => $row['label'], 'order' => (int) $row['urut'],
                'ref' => ['kel' => $row['kel'], 'level' => $row['level']],
            ]);
        }
        fclose($fh);

        Question::create(['block_id' => $b->id, 'code_rincian' => '(3)', 'var_name' => 'siv2a_sebulan',
            'label' => 'Pengeluaran sebulan terakhir (Rp)', 'qtype' => 'decimal', 'unit' => 'Rp', 'order' => 1]);
        Question::create(['block_id' => $b->id, 'code_rincian' => '(4)', 'var_name' => 'siv2a_tribulan',
            'label' => 'Pengeluaran tiga bulan terakhir (Rp)', 'qtype' => 'decimal', 'unit' => 'Rp', 'order' => 2]);

        // agregat: total dari rincian rinci vs total yang diisi petugas (rincian 451)
        $aggs = [
            ['siv2a_detail_sebulan', 'Σ rincian rinci · sebulan', 'siv2a_sebulan', 'detail'],
            ['siv2a_input_sebulan', 'Isian TOTAL (R451) · sebulan', 'siv2a_sebulan', 'total'],
            ['siv2a_detail_tribulan', 'Σ rincian rinci · tiga bulan', 'siv2a_tribulan', 'detail'],
            ['siv2a_input_tribulan', 'Isian TOTAL (R451) · tiga bulan', 'siv2a_tribulan', 'total'],
        ];
        foreach ($aggs as $i => [$name, $label, $var, $lvl]) {
            Aggregate::create([
                'qversion_id' => $qv->id, 'name' => $name, 'label' => $label,
                'block_code' => 'IV2A', 'value_var' => $var, 'op' => 'sum',
                'filter_var' => '', 'filter_op' => 'any', 'filter_val' => '',
                'filter_ref' => 'level', 'filter_ref_val' => $lvl, 'order' => $i + 1,
            ]);
        }

        foreach ([
            ['SRT-IV2A-NEG', 'anomali', 'IV2A', 'siv2a_sebulan',
                'filled(siv2a_sebulan) and num(siv2a_sebulan) < 0', 'error',
                'Pengeluaran bukan makanan bernilai negatif.'],
            ['SRT-IV2A-TRIBULAN', 'konsistensi', 'IV2A', 'siv2a_tribulan',
                'filled(siv2a_sebulan) and filled(siv2a_tribulan) and num(siv2a_tribulan) < num(siv2a_sebulan)', 'warning',
                'Nilai tiga bulan lebih kecil dari nilai sebulan pada rincian yang sama.'],
            ['SRT-IV2A-TOTAL-1', 'konsistensi', '', 'siv2a_input_sebulan',
                'siv2a_input_sebulan > 0 and abs(siv2a_input_sebulan - siv2a_detail_sebulan) > 1000', 'warning',
                'TOTAL pengeluaran bukan makanan (R451) tidak sama dengan jumlah rinciannya (sebulan).'],
            ['SRT-IV2A-TOTAL-3', 'konsistensi', '', 'siv2a_input_tribulan',
                'siv2a_input_tribulan > 0 and abs(siv2a_input_tribulan - siv2a_detail_tribulan) > 1000', 'warning',
                'TOTAL pengeluaran bukan makanan (R451) tidak sama dengan jumlah rinciannya (tiga bulan).'],
        ] as $i => [$code, $scope, $rowBlock, $target, $expr, $sev, $msg]) {
            ValidationRule::create([
                'qversion_id' => $qv->id, 'code' => $code, 'scope' => $scope,
                'row_block' => $rowBlock, 'target_var' => $target, 'expr' => $expr,
                'severity' => $sev, 'message' => $msg, 'active' => true,
            ]);
        }

        $this->sampleData($cycle, $qv);

        // Inc 156: blok III, IV.2.B, IV.3, V, VI, VII dari PDF VSERUTI26.INTI
        $this->call(SerutiIntiLengkapSeeder::class);
    }

    private function sampleData(SurveyCycle $cycle, QuestionnaireVersion $qv): void
    {
        $petugas = User::where('username', 'ppl_ani')->value('id');
        $pml = User::where('username', 'rahmawati')->value('id');
        $desa = Wilayah::where('level', 'desa')->where('code', 'like', '3526%')->pluck('code')->take(3)->all();
        if (! $desa) {
            return;
        }

        $engine = app(RuleEngine::class);
        $rowByCode = BlockRow::whereIn('block_id', Block::where('qversion_id', $qv->id)->pluck('id'))
            ->pluck('row_index', 'code');
        $qmap = Question::whereIn('block_id', Block::where('qversion_id', $qv->id)->pluck('id'))
            ->get()->keyBy('var_name');

        // dua ruta konsisten + satu dengan TOTAL tak cocok (untuk uji aturan)
        $fixtures = [
            ['art' => 4, 'detail' => ['402' => 150000, '403' => 80000, '408' => 220000, '410' => 90000,
                '419' => 300000, '422' => 120000, '428' => 150000, '438' => 90000], 'total451' => 1200000],
            ['art' => 2, 'detail' => ['402' => 60000, '408' => 140000, '410' => 65000, '428' => 100000], 'total451' => 365000],
            ['art' => 5, 'detail' => ['402' => 200000, '419' => 400000, '428' => 180000], 'total451' => 5000000],
        ];

        $seq = 0;
        foreach ($fixtures as $fi => $fx) {
            $seq++;
            $sample = SampleHousehold::create([
                'cycle_id' => $cycle->id, 'wilayah_code' => $desa[$fi % count($desa)],
                'no_bs' => sprintf('S%03d', 200 + $seq), 'no_ruta' => '001',
                'krt_name' => 'Ruta Seruti '.$seq, 'status' => 'selesai',
                'petugas_id' => $petugas, 'pml_id' => $pml, 'weight' => 150 + $seq * 20,
            ]);
            $doc = Document::create(['sample_id' => $sample->id, 'qversion_id' => $qv->id,
                'status' => $fi === 2 ? 'selesai' : 'bersih', 'created_by' => $petugas,
                'submitted_at' => now()->subDays(5 + $fi)]);

            if ($q = $qmap->get('banyak_art')) {
                Answer::create(['document_id' => $doc->id, 'question_id' => $q->id,
                    'art_index' => 0, 'raw_value' => (string) $fx['art']]);
            }
            $sebulan = $qmap->get('siv2a_sebulan');
            foreach ($fx['detail'] as $code => $val) {
                if ($sebulan && isset($rowByCode[$code])) {
                    Answer::create(['document_id' => $doc->id, 'question_id' => $sebulan->id,
                        'art_index' => (int) $rowByCode[$code], 'raw_value' => (string) $val]);
                }
            }
            if ($sebulan && isset($rowByCode['451'])) {
                Answer::create(['document_id' => $doc->id, 'question_id' => $sebulan->id,
                    'art_index' => (int) $rowByCode['451'], 'raw_value' => (string) $fx['total451']]);
            }
            $engine->run($doc);
        }
    }
}
