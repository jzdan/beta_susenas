<?php

namespace Tests\Feature;

use App\Models\SampleHousehold;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inc 121 — peran "pj" (Penanggung Jawab Kecamatan): satu per kecamatan,
 * menaungi beberapa PML, kewenangan setara PML tapi lingkup wilayah_scope
 * lebih luas (kecamatan, bukan desa).
 */
class PjRoleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function admin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    public function test_seed_creates_a_pj_scoped_to_one_kecamatan(): void
    {
        $pj = User::where('username', 'pj_kamal')->firstOrFail();
        $this->assertSame('pj', $pj->role);
        $this->assertSame('3526010', $pj->wilayah_scope);
        $this->assertSame('Penanggung Jawab (PJ) Kecamatan', $pj->roleLabel());
    }

    public function test_admin_rejects_pj_with_non_kecamatan_scope(): void
    {
        $this->actingAs($this->admin())->post('/admin/pengguna', [
            'username' => 'pj_x', 'name' => 'X', 'role' => 'pj',
            'wilayah_scope' => '3526', 'password' => 'password1',
        ])->assertSessionHasErrors('wilayah_scope');
        $this->assertDatabaseMissing('users', ['username' => 'pj_x']);
    }

    public function test_admin_rejects_pj_with_unknown_kecamatan_code(): void
    {
        $this->actingAs($this->admin())->post('/admin/pengguna', [
            'username' => 'pj_y', 'name' => 'Y', 'role' => 'pj',
            'wilayah_scope' => '9999999', 'password' => 'password1',
        ])->assertSessionHasErrors('wilayah_scope');
    }

    public function test_admin_rejects_second_active_pj_in_same_kecamatan(): void
    {
        $this->actingAs($this->admin())->post('/admin/pengguna', [
            'username' => 'pj_kamal2', 'name' => 'Dobel', 'role' => 'pj',
            'wilayah_scope' => '3526010', 'password' => 'password1',
        ])->assertSessionHasErrors('wilayah_scope');
        $this->assertDatabaseMissing('users', ['username' => 'pj_kamal2']);
    }

    public function test_admin_can_save_pj_own_record_without_self_duplicate_error(): void
    {
        $pj = User::where('username', 'pj_kamal')->firstOrFail();
        $this->actingAs($this->admin())->post('/admin/pengguna', [
            'user_id' => $pj->id, 'username' => $pj->username, 'name' => 'Bambang Wijaya (edit)',
            'role' => 'pj', 'wilayah_scope' => '3526010',
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('Bambang Wijaya (edit)', $pj->fresh()->name);
    }

    public function test_pj_sees_samples_across_whole_kecamatan_not_just_one_desa(): void
    {
        $pj = User::where('username', 'pj_kamal')->firstOrFail(); // scope 3526010

        $this->assertTrue(SampleHousehold::where('wilayah_code', '3526010001')->exists());
        $this->assertTrue(SampleHousehold::where('wilayah_code', '3526010002')->exists());

        $res = $this->actingAs($pj)->get('/monitoring/sampel');
        $res->assertOk();
        $codes = $res->viewData('items')->pluck('wilayah_code')->unique();
        $this->assertGreaterThan(1, $codes->count(), 'harus mencakup lebih dari satu desa di kecamatan itu');
        $this->assertTrue($codes->every(fn ($c) => str_starts_with($c, '3526010')));
    }

    public function test_pj_has_pml_level_powers_on_pengolahan_and_kinerja_and_audit(): void
    {
        $pj = $this->actingAs(User::where('username', 'pj_kamal')->firstOrFail());

        $pj->get('/pengolahan')->assertOk();
        $pj->get('/monitoring/kinerja')->assertOk();
        $pj->get('/monitoring/audit')->assertOk();
        $pj->get('/tabulasi')->assertOk();
    }

    public function test_pj_can_assign_petugas_to_a_sample_in_its_kecamatan(): void
    {
        $pj = User::where('username', 'pj_kamal')->firstOrFail();
        $op = User::where('username', 'ppl_dodi')->firstOrFail();
        $sample = SampleHousehold::where('wilayah_code', 'like', '3526010%')->firstOrFail();

        $this->actingAs($pj)->post("/monitoring/sampel/{$sample->id}/tugaskan", [
            'petugas_id' => $op->id, 'pml_id' => $pj->id,
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertSame($op->id, $sample->fresh()->petugas_id);
    }

    public function test_pj_is_not_offered_as_an_assignable_pml_for_samples(): void
    {
        $admin = $this->admin();
        $sample = SampleHousehold::first();

        $res = $this->actingAs($admin)->get("/monitoring/sampel/{$sample->id}");
        $assignable = $res->viewData('assignable');
        $this->assertArrayHasKey('pml', $assignable);
        $this->assertNotContains('Bambang Wijaya', $assignable['pml']);
    }
}
