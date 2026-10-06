<?php

namespace Tests\Feature;

use App\Models\QuestionnaireVersion;
use App\Models\SurveyCycle;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Susenas2026CycleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inc 123 — kerangka siklus & instrumen Susenas+Seruti 2026 (opsional, manual).
 * Tidak dipanggil DatabaseSeeder — jalankan lewat `Susenas2026CycleSeeder::class`.
 */
class Susenas2026CycleSeederTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_does_not_run_by_default_seed(): void
    {
        $this->assertSame(3, QuestionnaireVersion::count());
        $this->assertFalse(SurveyCycle::where('name', 'SUSENAS September 2026 (KP)')->exists());
    }

    public function test_creates_one_cycle_per_instrument_for_all_four_quarters(): void
    {
        $this->seed(Susenas2026CycleSeeder::class);

        $expected = [
            'SUSENAS Maret 2026 (KOR)' => 'VSEN26.K',
            'SUSENAS Maret 2026 (KP)' => 'VSEN26.KP',
            'SERUTI Maret 2026' => 'VSERUTI26.INTI',
            'SERUTI Juni 2026 (INTI)' => 'VSERUTI26.INTI-TW2',
            'SERUTI Juni 2026 (MAK)' => 'VSERUTI26.MAK-TW2',
            'SUSENAS September 2026 (Modul)' => 'VSEN26.M',
            'SUSENAS September 2026 (KP)' => 'VSEN26.KP-TW3',
            'SERUTI September 2026 (INTI)' => 'VSERUTI26.INTI-TW3',
            'SERUTI November 2026 (INTI)' => 'VSERUTI26.INTI-TW4',
            'SERUTI November 2026 (MAK)' => 'VSERUTI26.MAK-TW4',
        ];
        foreach ($expected as $cycleName => $code) {
            $cycle = SurveyCycle::where('name', $cycleName)->first();
            $this->assertNotNull($cycle, "siklus '{$cycleName}' harus ada");
            $qv = QuestionnaireVersion::where('cycle_id', $cycle->id)->first();
            $this->assertNotNull($qv, "qversion utk '{$cycleName}' harus ada");
            $this->assertSame($code, $qv->code);
        }

        // tiap siklus cuma punya SATU qversion (1 siklus = 1 instrumen)
        foreach (SurveyCycle::whereIn('name', array_keys($expected))->get() as $cycle) {
            $this->assertSame(1, QuestionnaireVersion::where('cycle_id', $cycle->id)->count());
        }
    }

    public function test_duplicated_instruments_carry_real_structure_but_placeholders_stay_empty(): void
    {
        $this->seed(Susenas2026CycleSeeder::class);

        // duplikat dari VSEN26.KP asli — harus punya blok & aturan yg sama
        $kpSrc = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $kpTw3 = QuestionnaireVersion::where('code', 'VSEN26.KP-TW3')->firstOrFail();
        $this->assertSame($kpSrc->blocks()->count(), $kpTw3->blocks()->count());
        $this->assertGreaterThan(0, $kpTw3->blocks()->count());
        $this->assertSame($kpSrc->rules()->count(), $kpTw3->rules()->count());

        $intiSrc = QuestionnaireVersion::where('code', 'VSERUTI26.INTI')->firstOrFail();
        $intiTw4 = QuestionnaireVersion::where('code', 'VSERUTI26.INTI-TW4')->firstOrFail();
        $this->assertSame($intiSrc->blocks()->count(), $intiTw4->blocks()->count());

        // placeholder yang belum ada strukturnya harus benar-benar kosong (bukan konten karangan)
        foreach (['VSEN26.M', 'VSERUTI26.MAK-TW2', 'VSERUTI26.MAK-TW4'] as $code) {
            $qv = QuestionnaireVersion::where('code', $code)->firstOrFail();
            $this->assertSame(0, $qv->blocks()->count(), "{$code} harus placeholder kosong");
        }
    }

    public function test_health_check_flags_the_empty_placeholders(): void
    {
        $this->seed(Susenas2026CycleSeeder::class);

        $this->artisan('susenas:health')
            ->expectsOutputToContain('Versi kuesioner tanpa blok')
            ->assertExitCode(1);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(Susenas2026CycleSeeder::class);
        $before = QuestionnaireVersion::count();
        $this->seed(Susenas2026CycleSeeder::class);
        $this->assertSame($before, QuestionnaireVersion::count());
    }
}
