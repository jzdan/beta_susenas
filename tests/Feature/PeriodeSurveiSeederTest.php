<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\QuestionnaireVersion;
use App\Models\SampleHousehold;
use App\Models\SurveyCycle;
use App\Models\ValidationRule;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\KosongkanAturanSeeder;
use Database\Seeders\PeriodeSurveiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Inc 164 — kerangka Periode Survei 2026 & 2027 (menggantikan Susenas2026CycleSeederTest).
 * Tidak dipanggil DatabaseSeeder saat tes — dijalankan sendiri di sini dengan "hari ini" = 26 Nov 2026.
 */
class PeriodeSurveiSeederTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-26 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cycle(string $name): SurveyCycle
    {
        $c = SurveyCycle::where('name', $name)->first();
        $this->assertNotNull($c, "siklus '{$name}' harus ada");

        return $c;
    }

    public function test_does_not_run_by_default_seed(): void
    {
        $this->assertSame(3, QuestionnaireVersion::count());
        $this->assertFalse(SurveyCycle::where('period', 'like', '2027-%')->exists());
    }

    public function test_two_years_one_cycle_per_instrument_with_schedule_inside_its_quarter(): void
    {
        $this->seed(PeriodeSurveiSeeder::class);

        foreach (PeriodeSurveiSeeder::YEARS as $year) {
            foreach (PeriodeSurveiSeeder::PATTERN as [$month, $survey, $instrument]) {
                $period = sprintf('%04d-%02d', $year, $month);
                $c = SurveyCycle::where('period', $period)->where('survey', $survey)
                    ->where('instrument', $instrument)->where('name', 'not like', '%(contoh)%')->get();
                $this->assertCount(1, $c, "{$survey} {$instrument} {$period}");
                $this->assertSame(1, $c[0]->versions()->count(), "1 siklus = 1 versi ({$c[0]->name})");
                $this->assertNull($c[0]->scheduleIssue(), "jadwal {$c[0]->name}");
            }
        }
        $this->assertSame('2027-04-15', $this->cycle('SERUTI Maret 2027 (INTI)')->field_end->toDateString());
        $codes = QuestionnaireVersion::pluck('code')->all();
        foreach (['VSEN27.K', 'VSEN27.KP', 'VSEN27.KP-TW3', 'VSEN27.M', 'VSERUTI27.INTI', 'VSERUTI27.INTI-TW4',
            'VSERUTI27.MAK-TW2', 'VSEN26.KP-TW3', 'VSERUTI26.INTI-TW2', 'VSERUTI26.MAK-TW4'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->assertSame(count($codes), count(array_unique($codes)), 'kode versi tidak boleh ganda');
    }

    public function test_status_follows_the_calendar(): void
    {
        $this->seed(PeriodeSurveiSeeder::class);

        $this->assertSame('selesai', $this->cycle('SERUTI Juni 2026 (INTI)')->status);
        $this->assertSame('pengolahan', $this->cycle('SUSENAS September 2026 (KP)')->status);
        $this->assertSame('lapangan', $this->cycle('SERUTI November 2026 (INTI)')->status);
        $this->assertSame(0, SurveyCycle::where('period', 'like', '2027-%')->where('status', '!=', 'persiapan')->count());
        // siklus Maret 2026 bawaan (data contoh utama) tidak diubah
        $this->assertSame('pengolahan', $this->cycle('SUSENAS Maret 2026 (KP)')->status);
        // tidak ada siklus yang memicu pengingat jadwal
        $this->assertFalse(SurveyCycle::get()->contains(fn ($c) => $c->fieldOverdue() || $c->fieldShouldHaveStarted()));
    }

    public function test_versions_copy_structure_and_placeholders_stay_empty(): void
    {
        $this->seed(PeriodeSurveiSeeder::class);

        $kp = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        foreach (['VSEN26.KP-TW3', 'VSEN27.KP'] as $code) {
            $v = QuestionnaireVersion::where('code', $code)->firstOrFail();
            $this->assertSame($kp->blocks()->count(), $v->blocks()->count(), $code);
            $this->assertSame($kp->rules()->count(), $v->rules()->count(), $code);
        }
        foreach (['VSEN26.M', 'VSEN27.M', 'VSERUTI26.MAK-TW2', 'VSERUTI27.MAK-TW4'] as $code) {
            $this->assertSame(0, QuestionnaireVersion::where('code', $code)->firstOrFail()->blocks()->count(), $code);
        }
    }

    public function test_example_samples_match_cycle_stage(): void
    {
        $this->seed(PeriodeSurveiSeeder::class);

        // selesai → semua isian bersih
        $juni = $this->cycle('SERUTI Juni 2026 (INTI)');
        $this->assertGreaterThan(0, $juni->samples()->count());
        $st = Document::whereIn('sample_id', $juni->samples()->pluck('id'))->pluck('status')->unique()->values()->all();
        $this->assertSame(['bersih'], $st);

        // pengolahan → ada yang menunggu pemeriksaan & dikembalikan; isian berisi jawaban
        $sep = $this->cycle('SUSENAS September 2026 (KP)');
        $docs = Document::whereIn('sample_id', $sep->samples()->pluck('id'))->get();
        $this->assertContains('selesai', $docs->pluck('status'));
        $this->assertContains('dikembalikan', $docs->pluck('status'));
        $this->assertGreaterThan(20, DB::table('answers')->where('document_id', $docs->first()->id)->count());
        $this->assertTrue($docs->every(fn ($d) => $d->submitted_at && $d->submitted_at->lte(now())));

        // lapangan → semua sampel sudah dikunjungi & berisi isian, kecuali ±3% non-respons
        $nov = $this->cycle('SERUTI November 2026 (INTI)');
        $this->assertFalse($nov->samples()->where('status', 'belum')->exists());
        $this->assertLessThanOrEqual(1, $nov->samples()->where('status', 'non_respons')->count());
        $this->assertSame($nov->samples()->where('status', '!=', 'non_respons')->count(),
            Document::whereIn('sample_id', $nov->samples()->pluck('id'))->count());

        // siklus contoh: sampel "belum dikunjungi" ikut diisi
        $contoh = SurveyCycle::where('name', 'like', '%(contoh)%')->firstOrFail();
        $this->assertFalse($contoh->samples()->where('status', 'belum')->exists());

        // persiapan & kuesioner kosong → tanpa sampel
        $this->assertSame(0, SampleHousehold::whereIn('cycle_id',
            SurveyCycle::where('period', 'like', '2027-%')->pluck('id'))->count());
        $this->assertSame(0, $this->cycle('SERUTI Juni 2026 (MAK)')->samples()->count());
    }

    public function test_synthetic_filler_completes_every_block(): void
    {
        $this->seed(PeriodeSurveiSeeder::class);

        // SERUTI: isian sumber hanya Blok Pokok & IV.2.A — blok lain dilengkapi pengisi sintetis
        $juni = $this->cycle('SERUTI Juni 2026 (INTI)');
        $doc = Document::whereIn('sample_id', $juni->samples()->pluck('id'))->firstOrFail();
        $byBlock = DB::table('answers')->join('questions', 'questions.id', '=', 'answers.question_id')
            ->join('blocks', 'blocks.id', '=', 'questions.block_id')->where('answers.document_id', $doc->id)
            ->groupBy('blocks.code')->selectRaw('blocks.code, count(*) n')->pluck('n', 'code');
        foreach (['POKOK', 'III', 'IV2A', 'IV2B', 'IV3', 'V3', 'V5', 'VI', 'VII'] as $code) {
            $this->assertGreaterThan(0, $byBlock[$code] ?? 0, "blok {$code} harus terisi");
        }
        // roster sebanyak "banyaknya anggota rumah tangga", anggota 1 = kepala rumah tangga
        $nArt = (int) DB::table('answers')->join('questions', 'questions.id', '=', 'answers.question_id')
            ->where('document_id', $doc->id)->where('questions.var_name', 'banyak_art')->value('raw_value');
        $hub = DB::table('answers')->join('questions', 'questions.id', '=', 'answers.question_id')
            ->where('document_id', $doc->id)->where('questions.var_name', 'sart_hub')->orderBy('art_index')->pluck('raw_value', 'art_index');
        $this->assertCount($nArt, $hub);
        $this->assertSame('1', (string) $hub[1]);
        // syarat lewati dihormati: rincian persentase hanya ada bila kecenderungannya "naik/turun"
        $ctx = app(\App\Services\RuleEngine::class)->buildContext($doc);
        if (! in_array((int) ($ctx['sv3_464'] ?? 0), [1, 3], true)) {
            $this->assertArrayNotHasKey('sv3_464_pct', array_filter($ctx, fn ($v) => $v !== null && $v !== ''));
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(PeriodeSurveiSeeder::class);
        $before = [QuestionnaireVersion::count(), SampleHousehold::count()];
        $this->seed(PeriodeSurveiSeeder::class);
        $this->assertSame($before, [QuestionnaireVersion::count(), SampleHousehold::count()]);
    }

    public function test_empty_rules_seeder_keeps_metadata(): void
    {
        $this->seed(PeriodeSurveiSeeder::class); // termasuk versi milik siklus 'selesai'
        $blocks = DB::table('blocks')->count();
        $this->assertGreaterThan(0, ValidationRule::count());

        $this->seed(KosongkanAturanSeeder::class);

        $this->assertSame(0, ValidationRule::count());
        $this->assertSame(0, DB::table('rule_results')->count());
        $this->assertSame($blocks, DB::table('blocks')->count());
        $this->assertSame(Document::count(), DB::table('document_snapshots')->count());
    }
}
