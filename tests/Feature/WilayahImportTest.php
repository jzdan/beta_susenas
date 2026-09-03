<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wilayah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WilayahImportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function admin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    public function test_import_orders_parents_first_and_commits(): void
    {
        $before = Wilayah::count();
        // sengaja: anak (desa) sebelum induk (kec) di CSV
        $csv = "code,name,level,parent_code\n"
            . "3526309999,Desa Baru,desa,352630\n"
            . "352630,Kecamatan Baru,kecamatan,3526\n";

        $this->actingAs($this->admin())->post('/admin/impor-wilayah', ['data' => $csv, 'mode' => 'commit'])
            ->assertOk();

        $this->assertSame($before + 2, Wilayah::count());
        $this->assertDatabaseHas('wilayah', ['code' => '3526309999', 'parent_code' => '352630']);
    }

    public function test_preview_does_not_persist(): void
    {
        $before = Wilayah::count();
        $csv = "code,name,level,parent_code\n352640,Kec X,kecamatan,3526\n";
        $res = $this->actingAs($this->admin())->post('/admin/impor-wilayah', ['data' => $csv, 'mode' => 'preview'])->assertOk();

        $this->assertTrue($res->viewData('hasil')['preview']);
        $this->assertSame(1, $res->viewData('hasil')['baru']);
        $this->assertSame($before, Wilayah::count());
    }

    public function test_bad_code_length_and_orphan_parent_are_skipped(): void
    {
        $csv = "code,name,level,parent_code\n"
            . "3526,Kab X,kabupaten,35\n"        // valid (update existing)
            . "999,Prov Pendek,provinsi,\n"      // panjang salah (prov butuh 2)
            . "3599101001,Desa Yatim,desa,359910\n";   // induk 359910 tak ada
        $res = $this->actingAs($this->admin())->post('/admin/impor-wilayah', ['data' => $csv, 'mode' => 'commit'])->assertOk();

        $skip = $res->viewData('hasil')['dilewati'];
        $this->assertCount(2, $skip);
        $this->assertStringContainsString('panjang kode', implode(' ', $skip));
        $this->assertStringContainsString('belum ada', implode(' ', $skip));
    }
}
