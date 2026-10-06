<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wilayah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wilayah scope pengguna selalu satu KECAMATAN (dropdown), bukan desa/kabupaten/teks
 * bebas — berlaku utk semua peran, bukan cuma PJ (yang aturan tambahannya ada di
 * PjRoleTest: wajib diisi + satu PJ per kecamatan).
 */
class UserWilayahScopeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function admin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    public function test_pml_scope_accepts_a_valid_kecamatan_code(): void
    {
        $kec = Wilayah::where('level', 'kecamatan')->firstOrFail();

        $this->actingAs($this->admin())->post('/admin/pengguna', [
            'username' => 'pml_baru', 'name' => 'PML Baru', 'role' => 'pml',
            'wilayah_scope' => $kec->code, 'password' => 'password1',
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertDatabaseHas('users', ['username' => 'pml_baru', 'wilayah_scope' => $kec->code]);
    }

    public function test_pml_scope_accepts_empty_for_no_restriction(): void
    {
        $this->actingAs($this->admin())->post('/admin/pengguna', [
            'username' => 'pml_semua', 'name' => 'PML Semua', 'role' => 'pml',
            'wilayah_scope' => '', 'password' => 'password1',
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertDatabaseHas('users', ['username' => 'pml_semua', 'wilayah_scope' => '']);
    }

    public function test_pml_scope_rejects_desa_level_code(): void
    {
        $desa = Wilayah::where('level', 'desa')->firstOrFail();

        $this->actingAs($this->admin())->post('/admin/pengguna', [
            'username' => 'pml_desa', 'name' => 'PML Desa', 'role' => 'pml',
            'wilayah_scope' => $desa->code, 'password' => 'password1',
        ])->assertSessionHasErrors('wilayah_scope');
        $this->assertDatabaseMissing('users', ['username' => 'pml_desa']);
    }

    public function test_pml_scope_rejects_kabupaten_level_code(): void
    {
        $kab = Wilayah::where('level', 'kabupaten')->firstOrFail();

        $this->actingAs($this->admin())->post('/admin/pengguna', [
            'username' => 'pml_kab', 'name' => 'PML Kab', 'role' => 'pml',
            'wilayah_scope' => $kab->code, 'password' => 'password1',
        ])->assertSessionHasErrors('wilayah_scope');
        $this->assertDatabaseMissing('users', ['username' => 'pml_kab']);
    }
}
