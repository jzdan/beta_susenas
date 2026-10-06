<?php

namespace Tests\Feature;

use App\Models\SampleHousehold;
use App\Models\SurveyCycle;
use App\Models\User;
use App\Models\Wilayah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WilayahBrowseTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function admin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    public function test_search_by_code_prefix_and_name(): void
    {
        // halaman sekarang selalu menampilkan semua kecamatan (dikelompokkan) — `q` cuma
        // menyaring: kecamatan yang cocok kodenya tampil dgn semua desanya, yang tidak
        // cocok cuma tampil kalau ada desa di dalamnya yang cocok.
        $byPrefix = collect($this->actingAs($this->admin())->get('/admin/impor-wilayah?q=3526020')->viewData('groups'));
        $this->assertCount(1, $byPrefix);
        $this->assertSame('3526020', $byPrefix->first()['kec']->code);
        $this->assertCount(2, $byPrefix->first()['desa']);

        // Kabupaten (satu-satunya, "Kabupaten Bangkalan") ditampilkan terpisah & tak
        // disaring oleh `q` — pencarian nama menyasar kecamatan/desa di dalam kelompok.
        $byName = collect($this->actingAs($this->admin())->get('/admin/impor-wilayah?q=Buduran')->viewData('groups'));
        $this->assertTrue($byName->contains(fn ($g) => $g['desa']->contains(fn ($d) => $d->name === 'Buduran')));
    }

    public function test_rename_updates_the_name(): void
    {
        $w = Wilayah::where('level', 'desa')->firstOrFail();

        $this->actingAs($this->admin())->post("/admin/wilayah/{$w->id}/nama", ['name' => 'Nama Baru Desa'])
            ->assertRedirect();

        $this->assertSame('Nama Baru Desa', $w->fresh()->name);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'wilayah', 'action' => 'ubah-nama']);
    }

    public function test_import_page_still_renders_after_a_preview(): void
    {
        $csv = "code,name,level\n3526990,Kec Baru,kecamatan\n";
        $this->actingAs($this->admin())->post('/admin/impor-wilayah', ['data' => $csv, 'mode' => 'preview'])
            ->assertOk()
            ->assertSee('Master Wilkerstat');
    }

    public function test_crud_add_single_wilayah(): void
    {
        // Inc 134 — kode induk tidak lagi diisi manual, diturunkan otomatis dari awalan kode.
        $this->actingAs($this->admin())->post('/admin/wilayah', [
            'code' => '3526990', 'name' => 'Kecamatan Uji', 'level' => 'kecamatan',
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertDatabaseHas('wilayah', ['code' => '3526990', 'name' => 'Kecamatan Uji', 'level' => 'kecamatan', 'parent_code' => '3526']);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'wilayah', 'action' => 'tambah', 'entity_id' => '3526990']);
    }

    public function test_crud_add_rejects_kabupaten_level(): void
    {
        // hanya 1 kabupaten di aplikasi ini (Bangkalan/3526, sudah ada sejak seed) —
        // "kabupaten" sengaja tidak ditawarkan sebagai jenjang yang bisa ditambah.
        $this->actingAs($this->admin())->post('/admin/wilayah', [
            'code' => '1234', 'name' => 'Kabupaten Lain', 'level' => 'kabupaten',
        ])->assertSessionHasErrors('level');
        $this->assertDatabaseMissing('wilayah', ['code' => '1234']);
    }

    public function test_crud_add_rejects_wrong_code_length_and_duplicate_and_missing_parent(): void
    {
        $admin = $this->actingAs($this->admin());

        $admin->post('/admin/wilayah', ['code' => '99', 'name' => 'X', 'level' => 'kecamatan'])
            ->assertSessionHasErrors('code');

        // induk diturunkan dari awalan kode (9999) — kabupaten itu tidak ada di sistem ini
        $admin->post('/admin/wilayah', ['code' => '9999999', 'name' => 'X', 'level' => 'kecamatan'])
            ->assertSessionHasErrors('code');

        $existing = Wilayah::where('level', 'kecamatan')->firstOrFail();
        $admin->post('/admin/wilayah', [
            'code' => $existing->code, 'name' => 'Duplikat', 'level' => 'kecamatan',
        ])->assertSessionHasErrors('code');
    }

    public function test_rename_ignores_parent_code_input(): void
    {
        // Inc 134 — parent_code tidak lagi bisa disunting manual (selalu ikut kode wilayah itu
        // sendiri); mengirim field ini seharusnya diabaikan diam-diam, bukan error.
        $kec = Wilayah::where('level', 'kecamatan')->firstOrFail();
        $originalParent = $kec->parent_code;
        $kab = Wilayah::where('level', 'kabupaten')->firstOrFail();

        $this->actingAs($this->admin())->post("/admin/wilayah/{$kec->id}/nama", [
            'name' => 'Nama Diubah', 'parent_code' => $kab->code.'999',
        ])->assertRedirect()->assertSessionHas('ok');

        $kec->refresh();
        $this->assertSame('Nama Diubah', $kec->name);
        $this->assertSame($originalParent, $kec->parent_code);
    }

    public function test_crud_delete_blocked_by_children_then_succeeds_once_childless(): void
    {
        $admin = $this->actingAs($this->admin());
        $leaf = Wilayah::create(['code' => '3526980', 'name' => 'Hapus Saya', 'level' => 'kecamatan', 'parent_code' => '3526']);

        $child = Wilayah::create(['code' => '3526980001', 'name' => 'Anak', 'level' => 'desa', 'parent_code' => '3526980']);
        $admin->delete("/admin/wilayah/{$leaf->id}")->assertSessionHasErrors('hapus');
        $this->assertDatabaseHas('wilayah', ['code' => '3526980']);

        $child->delete();
        $admin->delete("/admin/wilayah/{$leaf->id}")->assertRedirect()->assertSessionHas('ok');
        $this->assertDatabaseMissing('wilayah', ['code' => '3526980']);
    }

    public function test_crud_delete_blocked_when_used_by_sample(): void
    {
        $desa = Wilayah::where('level', 'desa')->firstOrFail();
        SampleHousehold::create([
            'cycle_id' => SurveyCycle::firstOrFail()->id, 'wilayah_code' => $desa->code,
            'no_bs' => '999X', 'no_ruta' => '999', 'status' => 'belum',
        ]);

        $this->actingAs($this->admin())->delete("/admin/wilayah/{$desa->id}")
            ->assertSessionHasErrors('hapus');
        $this->assertDatabaseHas('wilayah', ['code' => $desa->code]);
    }
}
