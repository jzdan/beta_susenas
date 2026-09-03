<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentSnapshot;
use App\Models\SampleHousehold;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TabulationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_weighted_mean_uses_sample_weights(): void
    {
        // dua dokumen dengan snapshot & penimbang terkendali
        [$a, $b] = SampleHousehold::query()->take(2)->get();
        $a->update(['weight' => 100]);
        $b->update(['weight' => 300]);
        DocumentSnapshot::where('document_id', Document::where('sample_id', $a->id)->value('id'))
            ->update(['data' => json_encode(['uji_var' => 10])]);
        DocumentSnapshot::where('document_id', Document::where('sample_id', $b->id)->value('id'))
            ->update(['data' => json_encode(['uji_var' => 20])]);

        $admin = User::where('username', 'admin')->firstOrFail();
        $res = $this->actingAs($admin)->get('/tabulasi?' . http_build_query([
            'var' => 'uji_var', 'group' => '', 'agg' => 'wmean', 'selesai' => 0,
        ]));
        $res->assertOk();

        // rata-rata tertimbang = (100*10 + 300*20) / 400 = 17.5
        $res->assertSee('17,5');
        // estimasi populasi = 400
        $this->get('/tabulasi?' . http_build_query(['var' => 'uji_var', 'group' => '', 'agg' => 'wcount', 'selesai' => 0]));
    }

    public function test_seeded_samples_have_weights(): void
    {
        $this->assertSame(0, SampleHousehold::whereNull('weight')->count());
        $this->assertGreaterThan(0, SampleHousehold::where('weight', '>', 0)->count());
    }

    public function test_cross_tab_builds_a_matrix(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $res = $this->actingAs($admin)->get('/tabulasi?' . http_build_query([
            'var' => 'pengeluaran_per_kapita_bulan', 'group' => 'kecamatan', 'group2' => 'status',
            'agg' => 'mean', 'selesai' => 0,
        ]))->assertOk();

        $matrix = $res->viewData('matrix');
        $this->assertIsArray($matrix);
        $cols = $res->viewData('colKeys');
        foreach ($matrix as $line) {
            $this->assertSame(count($cols), count($line['cells']));   // tiap baris punya semua kolom
        }
    }

    public function test_tabulation_exports_as_csv(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $res = $this->actingAs($admin)->get('/tabulasi?' . http_build_query([
            'var' => 'pengeluaran_per_kapita_bulan', 'group' => 'kecamatan', 'agg' => 'mean',
            'selesai' => 0, 'export' => 1,
        ]));
        $res->assertOk();
        $res->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('pengeluaran_per_kapita_bulan', $res->streamedContent());
    }

    public function test_welfare_indicators_compute_headcount_and_food_share(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $res = $this->actingAs($admin)->get('/tabulasi/indikator?selesai=1&gk=550000');
        $res->assertOk();

        $rows = $res->viewData('result');
        $this->assertNotEmpty($rows);
        $total = $rows[0];                              // baris pertama = seluruh cakupan
        $this->assertSame('Semua wilayah', $total['label']);
        $this->assertGreaterThan(0, $total['n']);
        $this->assertGreaterThanOrEqual(0, $total['p0_u']);
        $this->assertLessThanOrEqual(100, $total['p0_u']);
        $this->assertGreaterThan($total['median'], $total['mean']);   // pengeluaran menceng kanan
        $this->assertIsFloat($total['food_share']);
        $this->assertSame(4, count($total['dist']));
        $this->assertSame($total['n'], array_sum($total['dist']));    // sebaran kelas menjumlah ke n

        // histogram: batang menjumlah ke n (dalam skala) + n di atas skala
        $hist = $res->viewData('hist');
        $this->assertNotNull($hist);
        $inScale = array_sum(array_column($hist['bars'], 'count'));
        $this->assertSame($total['n'], $inScale + $hist['over']);
        $this->assertGreaterThan(0, $hist['gk_x']);
    }

    public function test_flat_export_respects_selected_columns(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $full = $this->actingAs($admin)->get('/tabulasi/ekspor-data')->streamedContent();
        $header = explode("\n", ltrim($full, "\xEF\xBB\xBF"))[0];
        $this->assertStringContainsString('banyak_art', $header);

        $picked = $this->actingAs($admin)->get('/tabulasi/ekspor-data?'
            .http_build_query(['cols' => ['banyak_art']]))->streamedContent();
        $h2 = explode("\n", ltrim($picked, "\xEF\xBB\xBF"))[0];

        $this->assertStringContainsString('banyak_art', $h2);
        // kolom lain yang ada di ekspor penuh tidak muncul lagi
        $this->assertStringNotContainsString('pengeluaran_per_kapita', $h2);
        // kolom metadata tetap ada
        $this->assertStringContainsString('wilayah_code', $h2);
    }
}
