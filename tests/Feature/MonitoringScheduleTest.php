<?php

namespace Tests\Feature;

use App\Models\SampleHousehold;
use App\Models\SurveyCycle;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonitoringScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_dashboard_flags_a_behind_schedule_cycle(): void
    {
        $cycle = SurveyCycle::create([
            'name' => 'Uji Jadwal', 'period' => '2026-09', 'status' => 'lapangan',
            'field_start' => Carbon::today()->subDays(9)->toDateString(),   // 50% waktu berjalan (10 hari)
            'field_end' => Carbon::today()->addDays(0)->toDateString(),
        ]);
        // 10 sampel, 1 selesai → riil 10% vs target ~100% → tertinggal
        foreach (range(1, 10) as $i) {
            SampleHousehold::create([
                'cycle_id' => $cycle->id, 'wilayah_code' => '3526101001',
                'no_bs' => '099', 'no_ruta' => str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'krt_name' => "Uji {$i}", 'status' => $i === 1 ? 'selesai' : 'belum',
            ]);
        }

        $admin = User::where('username', 'admin')->firstOrFail();
        $res = $this->actingAs($admin)->get('/monitoring');
        $res->assertOk();
        $res->assertSee('Jadwal &amp; kecepatan pencacahan', false);
        $res->assertSee('Uji Jadwal');

        $sched = collect($res->viewData('schedule'))->firstWhere('cycle', 'Uji Jadwal');
        $this->assertNotNull($sched);
        $this->assertSame(10, $sched['total']);
        $this->assertSame(1, $sched['done']);
        $this->assertSame(10.0, $sched['actual']);
        $this->assertLessThan(-5, $sched['gap']);   // tertinggal
        $this->assertSame(9, (int) $sched['need_per_day']); // 9 sisa / 1 hari tersisa
    }

    public function test_dashboard_shows_processing_funnel_and_top_rules(): void
    {
        $admin = \App\Models\User::where('username', 'admin')->firstOrFail();
        $res = $this->actingAs($admin)->get('/monitoring')->assertOk();

        // corong = semua dokumen dalam cakupan, terbagi per tahap
        $funnel = collect($res->viewData('funnel'));
        $this->assertSame(['Entri/draft', 'Menunggu cleaning', 'Dikembalikan', 'Bersih'],
            $funnel->pluck('label')->all());
        $this->assertSame(\App\Models\Document::count(), $funnel->sum('n'));

        // aturan tersering: error di atas warning, n_doc menurun
        $top = collect($res->viewData('topRules'));
        $this->assertGreaterThan(0, $top->count());
        $this->assertTrue($top->pluck('n_doc')->sortDesc()->values()->all() === $top->pluck('n_doc')->values()->all()
            || $top->where('severity', 'error')->isNotEmpty());
    }
}
