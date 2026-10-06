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
        // sengaja: anak (desa) sebelum induk (kec) di CSV; induk diturunkan dari awalan kode (Inc 134)
        $csv = "code,name,level\n"
            . "3526300999,Desa Baru,desa\n"
            . "3526300,Kecamatan Baru,kecamatan\n";

        $this->actingAs($this->admin())->post('/admin/impor-wilayah', ['data' => $csv, 'mode' => 'commit'])
            ->assertOk();

        $this->assertSame($before + 2, Wilayah::count());
        $this->assertDatabaseHas('wilayah', ['code' => '3526300999', 'parent_code' => '3526300']);
    }

    public function test_preview_does_not_persist(): void
    {
        $before = Wilayah::count();
        $csv = "code,name,level\n3526400,Kec X,kecamatan\n";
        $res = $this->actingAs($this->admin())->post('/admin/impor-wilayah', ['data' => $csv, 'mode' => 'preview'])->assertOk();

        $this->assertTrue($res->viewData('hasil')['preview']);
        $this->assertSame(1, $res->viewData('hasil')['baru']);
        $this->assertSame($before, Wilayah::count());
    }

    public function test_bad_code_length_and_orphan_parent_are_skipped(): void
    {
        $csv = "code,name,level\n"
            . "3526,Kab X,kabupaten\n"                 // valid (update existing, jenjang teratas)
            . "99,Kec Pendek,kecamatan\n"               // panjang salah (kec butuh 7)
            . "9999999001,Desa Yatim,desa\n";           // induk turunan 9999999 tak ada
        $res = $this->actingAs($this->admin())->post('/admin/impor-wilayah', ['data' => $csv, 'mode' => 'commit'])->assertOk();

        $skip = $res->viewData('hasil')['dilewati'];
        $this->assertCount(2, $skip);
        $this->assertStringContainsString('panjang kode', implode(' ', $skip));
        $this->assertStringContainsString('belum ada', implode(' ', $skip));
    }
}
