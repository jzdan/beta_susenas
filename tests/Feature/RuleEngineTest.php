<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\RuleResult;
use App\Services\RuleEngine;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuleEngineTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_seed_produces_expected_rule_counts(): void
    {
        // hasil harus stabil & sama dengan baseline prototipe
        $expected = [
            1 => ['err' => 2, 'warn' => 3],
            2 => ['err' => 0, 'warn' => 2],
            3 => ['err' => 5, 'warn' => 2],
            4 => ['err' => 1, 'warn' => 3],
        ];
        foreach ($expected as $docId => $exp) {
            $e = RuleResult::where('document_id', $docId)->where('status', 'error')->count();
            $w = RuleResult::where('document_id', $docId)->where('status', 'warning')->count();
            $this->assertSame($exp['err'], $e, "doc {$docId} error");
            $this->assertSame($exp['warn'], $w, "doc {$docId} warning");
        }
    }

    public function test_neraca_and_perbaris_rules_fire(): void
    {
        $codes = fn ($id) => RuleResult::where('document_id', $id)
            ->whereIn('status', ['error', 'warning'])
            ->join('validation_rules', 'validation_rules.id', '=', 'rule_results.rule_id')
            ->pluck('validation_rules.code')->all();

        $this->assertContains('NERACA-PRODUKSI', $codes(3));
        $this->assertContains('IV1-KUANTITAS', $codes(3));
        $this->assertContains('NERACA-TRANSFER', $codes(4));
        $this->assertNotEmpty(array_intersect(['IV1-HARGA-MAHAL', 'IV1-HARGA-MURAH'], $codes(4)));
        $this->assertNotContains('NERACA-BALANCE', $codes(1)); // dok bersih tidak fire
    }

    public function test_correcting_clean_value_clears_error(): void
    {
        $engine = app(RuleEngine::class);
        $doc = Document::find(3);

        $before = RuleResult::where('document_id', 3)->where('status', 'error')->count();

        // isi kode wilayah & banyak_art lewat clean_value
        foreach (['kode_wilayah' => '3526101001', 'banyak_art' => '1'] as $var => $val) {
            $q = \App\Models\Question::where('var_name', $var)->firstOrFail();
            \App\Models\Answer::updateOrCreate(
                ['document_id' => 3, 'question_id' => $q->id, 'art_index' => 0],
                ['raw_value' => '', 'clean_value' => $val],
            );
        }
        $sum = $engine->run($doc);

        $this->assertLessThan($before, $sum['errors']);
    }

    public function test_snapshot_written_on_run(): void
    {
        $this->assertSame(
            \App\Models\Document::count(),
            \App\Models\DocumentSnapshot::count(),
        );
        $snap = \App\Models\DocumentSnapshot::where('document_id', 1)->firstOrFail();
        $this->assertArrayHasKey('pengeluaran_per_kapita', $snap->data);
        $this->assertArrayHasKey('kalori_per_kapita_hari', $snap->data);
    }

    public function test_sidona_anomaly_rules_on_real_vs_synthetic_household(): void
    {
        $kor = \App\Models\QuestionnaireVersion::where('code', 'VSEN26.K')->firstOrFail();
        $byKrt = fn (string $krt) => \App\Models\Document::where('qversion_id', $kor->id)
            ->whereHas('sample', fn ($q) => $q->where('krt_name', $krt))->firstOrFail();

        // Invarian: rumah tangga NYATA (data Sidona terisi) harus bersih dari anomali.
        // Ini yang benar-benar penting — mesin tidak boleh salah menyala pada data sah.
        foreach (['YUDI', 'SUPINI'] as $krt) {
            $this->assertSame(0, \App\Models\RuleResult::where('document_id', $byKrt($krt)->id)
                ->whereIn('status', ['error', 'warning'])->count(), "{$krt} seharusnya 0 anomali");
        }

        // Sintetis: dirancang melanggar banyak aturan. Assertion berbasis ambang +
        // perwakilan tiap gelombang — menambah/menghapus satu aturan tak mengubah tes,
        // tetapi regresi besar (mesin/roster/evaluator rusak) tetap tertangkap.
        $codes = \App\Models\RuleResult::where('document_id', $byKrt('CONTOH ANOMALI')->id)
            ->whereIn('status', ['error', 'warning'])
            ->join('validation_rules', 'validation_rules.id', '=', 'rule_results.rule_id')
            ->pluck('validation_rules.code')->unique();

        $this->assertGreaterThanOrEqual(55, $codes->count(),
            'rumah tangga sintetis seharusnya memicu ≥ 55 aturan berbeda');

        // satu perwakilan tiap gelombang terjemahan (lihat docs/pemetaan-aturan-sidona.md)
        foreach ([
            'SDN-03',   // g1: umur kawin pertama
            'SDN-05',   // g2: pointer antar-ART (roster_first)
            'SDN-01',   // g3: jam wawancara (Blok II)
            'SDN-89',   // g4: sanitasi/perumahan (Blok XVI)
            'SDN-97',   // g5: inklusi keuangan (roster_all)
            'SDN-40',   // g6: ketenagakerjaan lanjutan
            'SDN-61',   // g7: MP-ASI tanpa jenis makanan (checklist R1312)
            'SDN-65',   // g7: lahir di faskes tapi bayi tidak ditimbang
            'SDN-48',   // g8: akses internet PC hanya saat bergerak (checklist R806)
            'SDN-50',   // g8: internet banking tanpa rekening (roster_all)
        ] as $c) {
            $this->assertContains($c, $codes->all(), "perwakilan {$c} seharusnya menyala");
        }
    }

    public function test_iv1_group_rekap_aggregates_reconcile(): void
    {
        $qv = \App\Models\QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $doc = \App\Models\Document::where('qversion_id', $qv->id)->firstOrFail();
        $ctx = app(RuleEngine::class)->buildContext($doc);

        // 14 agregat subtotal nilai per kelompok + total harus rekonsiliasi
        $groupTotals = 0.0;
        foreach (\App\Models\Aggregate::where('qversion_id', $qv->id)
            ->where('name', 'like', 'iv1_nilai_%')->where('filter_ref', 'group')->get() as $agg) {
            $groupTotals += (float) ($ctx[$agg->name] ?? 0);
        }
        $this->assertEqualsWithDelta((float) $ctx['iv1_belanja_minggu'], $groupTotals, 1.0);

        // Blok IV.3.2: SUB JUMLAH = pembelian + produksi sendiri/pemberian; R.16 = x 30/7
        $this->assertEqualsWithDelta(
            (float) $ctx['iv1_belanja_minggu'] + (float) $ctx['iv1_lain_minggu'],
            (float) $ctx['rekap_iv32_subjumlah'], 1.0);
        $this->assertEqualsWithDelta(
            round((float) $ctx['rekap_iv32_subjumlah'] * 30 / 7),
            (float) $ctx['rekap_makanan_sebulan'], 1.0);

        // aturan konsistensi tidak menyala pada dokumen contoh yang seimbang
        $codes = \App\Models\RuleResult::where('document_id', $doc->id)
            ->whereIn('status', ['error', 'warning'])
            ->join('validation_rules', 'validation_rules.id', '=', 'rule_results.rule_id')
            ->pluck('validation_rules.code')->all();
        $this->assertNotContains('REKAP-IV1-NILAI', $codes);
        $this->assertNotContains('REKAP-IV1-LAIN', $codes);
    }

    public function test_iv2_nonfood_rekap_rolls_up_to_household_expenditure(): void
    {
        $qv = \App\Models\QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $doc = \App\Models\Document::where('qversion_id', $qv->id)->firstOrFail();
        $ctx = app(RuleEngine::class)->buildContext($doc);

        // Blok IV.3.3: nilai bulanan ekuivalen per seksi = sebulan + setahun/12
        $sum = 0.0;
        foreach (\App\Models\DerivedField::where('qversion_id', $qv->id)
            ->where('var_name', 'like', 'iv33_%')->pluck('var_name') as $v) {
            $sum += (float) ($ctx[$v] ?? 0);
        }
        $this->assertEqualsWithDelta($sum, (float) $ctx['rekap_bukan_makanan_sebulan'], 2.0);

        // R.9 = rekap makanan sebulan + rekap bukan makanan sebulan
        $this->assertEqualsWithDelta(
            (float) $ctx['rekap_makanan_sebulan'] + (float) $ctx['rekap_bukan_makanan_sebulan'],
            (float) $ctx['rekap_ruta_sebulan'], 1.0);

        // per kapita = R.9 / banyak ART, dan memicu QC kemiskinan pada dokumen contoh
        $this->assertEqualsWithDelta(
            round((float) $ctx['rekap_ruta_sebulan'] / 4),
            (float) $ctx['pengeluaran_per_kapita_bulan'], 1.0);

        $codes = \App\Models\RuleResult::where('document_id', $doc->id)
            ->whereIn('status', ['error', 'warning'])
            ->join('validation_rules', 'validation_rules.id', '=', 'rule_results.rule_id')
            ->pluck('validation_rules.code')->all();
        $this->assertContains('QC-MISKIN-KP', $codes);
        $this->assertNotContains('IV2-PERIODE', $codes);
    }

    public function test_blok_vi_reconciles_income_and_expenditure(): void
    {
        $qv = \App\Models\QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $doc = \App\Models\Document::where('qversion_id', $qv->id)->firstOrFail();
        $ctx = app(RuleEngine::class)->buildContext($doc);

        // konsumsi setahun Blok VI = rekap rumah tangga sebulan x 12
        $this->assertEqualsWithDelta(
            round((float) $ctx['rekap_ruta_sebulan'] * 12),
            (float) $ctx['vi_konsumsi_setahun'], 1.0);

        // selisih = penerimaan - pengeluaran
        $this->assertEqualsWithDelta(
            (float) $ctx['vi_penerimaan'] - (float) $ctx['vi_pengeluaran'],
            (float) $ctx['vi_selisih'], 1.0);

        // dokumen contoh seimbang (selisih < 10%) → NERACA-VI tidak menyala
        $codes = \App\Models\RuleResult::where('document_id', $doc->id)
            ->whereIn('status', ['error', 'warning'])
            ->join('validation_rules', 'validation_rules.id', '=', 'rule_results.rule_id')
            ->pluck('validation_rules.code')->all();
        $this->assertNotContains('NERACA-VI', $codes);
    }

    public function test_blok_vii_financial_transactions_reconcile_with_blok_vi(): void
    {
        $qv = \App\Models\QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $doc = \App\Models\Document::where('qversion_id', $qv->id)->firstOrFail();
        $ctx = app(RuleEngine::class)->buildContext($doc);

        // selisih transaksi keuangan = pengeluaran - penerimaan
        $this->assertEqualsWithDelta(
            (float) $ctx['vii_pengeluaran'] - (float) $ctx['vii_penerimaan'],
            (float) $ctx['vii_selisih'], 1.0);

        // identitas neraca: surplus Blok VI ≈ transaksi keuangan neto Blok VII
        $this->assertLessThan(
            0.15 * max(abs((float) $ctx['vi_selisih']), abs((float) $ctx['vii_selisih']), 1_000_000),
            abs((float) $ctx['vi_selisih'] - (float) $ctx['vii_selisih']));

        $codes = \App\Models\RuleResult::where('document_id', $doc->id)
            ->whereIn('status', ['error', 'warning'])
            ->join('validation_rules', 'validation_rules.id', '=', 'rule_results.rule_id')
            ->pluck('validation_rules.code')->all();
        $this->assertNotContains('NERACA-VII', $codes);
    }

    public function test_blok_iii_roster_consistency_rules(): void
    {
        $qv = \App\Models\QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $doc = \App\Models\Document::where('qversion_id', $qv->id)->firstOrFail();
        $engine = app(RuleEngine::class);
        $codes = fn () => \App\Models\RuleResult::where('document_id', $doc->id)
            ->whereIn('status', ['error', 'warning'])
            ->join('validation_rules', 'validation_rules.id', '=', 'rule_results.rule_id')
            ->pluck('validation_rules.code')->all();

        $engine->run($doc);
        $this->assertNotContains('III-JML-ART', $codes());
        $this->assertNotContains('III-KRT-TUNGGAL', $codes());
        $this->assertNotContains('III-KAWIN-ANAK', $codes());

        // rusak: ART umur 9 dijadikan "kawin"; dan tambah KRT kedua
        $qKp = fn (string $var) => \App\Models\Question::query()
            ->whereIn('block_id', \App\Models\Block::where('qversion_id', $qv->id)->pluck('id'))
            ->where('var_name', $var)->firstOrFail();
        $kawin = $qKp('art_kawin');
        $hub = $qKp('art_hub_krt');
        \App\Models\Answer::where('document_id', $doc->id)->where('question_id', $kawin->id)
            ->where('art_index', 4)->update(['raw_value' => '2']);
        \App\Models\Answer::where('document_id', $doc->id)->where('question_id', $hub->id)
            ->where('art_index', 2)->update(['raw_value' => '1']);   // ART 2 juga jadi KRT
        $engine->run($doc);

        $this->assertContains('III-KAWIN-ANAK', $codes());
        $this->assertContains('III-KRT-TUNGGAL', $codes());
    }

    public function test_blok_i_identity_checked_against_sample_metadata(): void
    {
        $qv = \App\Models\QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $doc = \App\Models\Document::where('qversion_id', $qv->id)->firstOrFail();
        $engine = app(RuleEngine::class);

        // konsisten dulu → IDENT-* tidak menyala
        $codes = fn () => \App\Models\RuleResult::where('document_id', $doc->id)
            ->whereIn('status', ['error', 'warning'])
            ->join('validation_rules', 'validation_rules.id', '=', 'rule_results.rule_id')
            ->pluck('validation_rules.code')->all();
        $engine->run($doc);
        $this->assertNotContains('IDENT-WILAYAH', $codes());
        $this->assertNotContains('II-TANGGAL', $codes());

        // rusak: kode wilayah Blok I dibuat beda dari sampel
        $q = \App\Models\Question::where('var_name', 'b1_kode_wilayah')->firstOrFail();
        \App\Models\Answer::where('document_id', $doc->id)->where('question_id', $q->id)
            ->update(['raw_value' => '3526209999']);
        // tanggal periksa dibuat sebelum tanggal cacah
        $qp = \App\Models\Question::where('var_name', 'b2_tgl_periksa')->firstOrFail();
        \App\Models\Answer::where('document_id', $doc->id)->where('question_id', $qp->id)
            ->update(['raw_value' => '2026-03-01']);
        $engine->run($doc);

        $this->assertContains('IDENT-WILAYAH', $codes());
        $this->assertContains('II-TANGGAL', $codes());
    }

    public function test_roster_functions_available_in_expressions(): void
    {
        $ev = app(\App\Services\ExpressionEvaluator::class);
        $funcs = \App\Services\RuleEngine::rosterFuncs([
            'r403' => [1 => '1', 2 => '2', 3 => '3'],
            'r405' => [1 => '1', 2 => '2', 3 => '1'],
        ]);
        $this->assertSame(2.0, $ev->evaluate('roster_count(r405, 1)', [], $funcs));
        $this->assertSame('2', $ev->evaluate('roster(r405, 2)', [], $funcs));
        $this->assertSame('2', $ev->evaluate('roster_first(r405, r403, 2)', [], $funcs));
        $this->assertTrue($ev->evaluate('roster_any(r403, 3)', [], $funcs));
        $this->assertFalse($ev->evaluate('roster_all(r403, 1)', [], $funcs));
    }
}
