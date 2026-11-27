<?php

namespace Tests\Feature;

use App\Models\SampleHousehold;
use App\Models\SurveyCycle;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResponseRateTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function freshCycleSamples(): SurveyCycle
    {
        $cycle = SurveyCycle::create(['name' => 'Uji Respons', 'period' => '2026-10', 'status' => 'lapangan']);
        // 10 sampel: 6 selesai, 1 non-respons (menolak), 1 non-respons (bangunan kosong = tidak eligible),
        // 1 diganti (ganti_sampel) + penggantinya belum, 1 belum
        $mk = fn (array $x) => SampleHousehold::create($x + [
            'cycle_id' => $cycle->id, 'wilayah_code' => '3526010001', 'no_bs' => '077', 'krt_name' => 'x',
        ]);
        foreach (range(1, 6) as $i) {
            $mk(['no_ruta' => "00{$i}", 'status' => 'selesai']);
        }
        $mk(['no_ruta' => '007', 'status' => 'non_respons', 'nonresponse_reason' => 'menolak']);
        $mk(['no_ruta' => '008', 'status' => 'non_respons', 'nonresponse_reason' => 'bangunan_kosong']);
        $asal = $mk(['no_ruta' => '009', 'status' => 'ganti_sampel', 'nonresponse_reason' => 'pindah']);
        $mk(['no_ruta' => '009P', 'status' => 'belum', 'replaces_id' => $asal->id]);
        $mk(['no_ruta' => '010', 'status' => 'belum']);

        return $cycle;
    }

    public function test_dashboard_excludes_replaced_and_ineligible_from_response_rate(): void
    {
        $this->freshCycleSamples();
        $admin = User::where('username', 'admin')->firstOrFail();

        $res = $this->actingAs($admin)->get('/monitoring?cycle=semua')->assertOk();
        $r = $res->viewData('response');

        // seed lain juga ada; batasi asersi pada delta yang kita tambahkan
        // total aktif = semua - ganti_sampel; eligible = aktif - (kosong/ganda)
        $this->assertSame($r['eligible'], $r['responded'] + $r['nonresponse'] + $r['pending']);
        $this->assertGreaterThanOrEqual(1, $r['replaced']);
        $this->assertGreaterThanOrEqual(1, $r['ineligible']);
        $this->assertLessThanOrEqual(100.0, $r['rate']);

        $res->assertSee('Hasil kunjungan', false);
    }

    public function test_replaced_original_not_counted_in_completion_total(): void
    {
        $this->freshCycleSamples();
        $admin = User::where('username', 'admin')->firstOrFail();

        $before = $this->actingAs($admin)->get('/monitoring?cycle=semua')->viewData('total');

        // menambah satu sampel yang langsung diganti tidak menaikkan total aktif
        $c = SurveyCycle::where('name', 'Uji Respons')->first();
        $asal = SampleHousehold::create(['cycle_id' => $c->id, 'wilayah_code' => '3526010001',
            'no_bs' => '077', 'no_ruta' => '011', 'krt_name' => 'x', 'status' => 'ganti_sampel']);
        SampleHousehold::create(['cycle_id' => $c->id, 'wilayah_code' => '3526010001',
            'no_bs' => '077', 'no_ruta' => '011P', 'krt_name' => 'x', 'status' => 'belum', 'replaces_id' => $asal->id]);

        $after = $this->actingAs($admin)->get('/monitoring?cycle=semua')->viewData('total');
        $this->assertSame($before + 1, $after, 'hanya pengganti yang menambah total, bukan sampel asal');
    }
}
