<?php

namespace Tests\Feature;

use App\Models\QuestionnaireVersion;
use App\Models\SampleHousehold;
use App\Models\SurveyCycle;
use App\Models\User;
use App\Models\Wilayah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Form "Tambah satu sampel" di Admin · Impor Sampel (pelengkap impor CSV massal). */
class SampleAddSingleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function admin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    public function test_admin_can_add_one_sample_via_form(): void
    {
        $cycle = QuestionnaireVersion::active()->cycle;
        $desa = Wilayah::where('level', 'desa')->firstOrFail();
        $op = User::where('role', 'operator')->firstOrFail();
        $pml = User::where('role', 'pml')->firstOrFail();

        $this->actingAs($this->admin())->post('/admin/sampel', [
            'cycle_id' => $cycle->id, 'wilayah_code' => $desa->code,
            'no_bs' => '099X', 'no_ruta' => '099', 'krt_name' => 'Contoh KRT',
            'petugas_id' => $op->id, 'pml_id' => $pml->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('sample_households', [
            'cycle_id' => $cycle->id, 'wilayah_code' => $desa->code,
            'no_bs' => '099X', 'no_ruta' => '099', 'krt_name' => 'Contoh KRT',
            'petugas_id' => $op->id, 'pml_id' => $pml->id, 'status' => 'belum',
        ]);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'sample', 'action' => 'tambah']);
    }

    public function test_rejects_wilayah_code_not_in_master(): void
    {
        $cycle = SurveyCycle::firstOrFail();

        $this->actingAs($this->admin())->post('/admin/sampel', [
            'cycle_id' => $cycle->id, 'wilayah_code' => '9999999999', 'no_bs' => '001', 'no_ruta' => '001',
        ])->assertSessionHasErrors('wilayah_code');
        $this->assertDatabaseMissing('sample_households', ['wilayah_code' => '9999999999']);
    }

    public function test_rejects_duplicate_wilayah_bs_ruta_in_same_cycle(): void
    {
        $existing = SampleHousehold::firstOrFail();

        $this->actingAs($this->admin())->post('/admin/sampel', [
            'cycle_id' => $existing->cycle_id, 'wilayah_code' => $existing->wilayah_code,
            'no_bs' => $existing->no_bs, 'no_ruta' => $existing->no_ruta,
        ])->assertSessionHasErrors('no_ruta');
    }

    public function test_non_admin_cannot_add_sample(): void
    {
        $cycle = SurveyCycle::firstOrFail();
        $desa = Wilayah::where('level', 'desa')->firstOrFail();
        $op = User::where('role', 'operator')->firstOrFail();

        $this->actingAs($op)->post('/admin/sampel', [
            'cycle_id' => $cycle->id, 'wilayah_code' => $desa->code, 'no_bs' => '098X', 'no_ruta' => '098',
        ])->assertForbidden();
    }

    /**
     * Dropdown Petugas di form disaring per kecamatan lewat JS — ini menguji
     * pertahanan sisi server-nya (kalau request dipaksa lewat filter itu).
     */
    public function test_rejects_petugas_scoped_to_a_different_kecamatan(): void
    {
        $cycle = QuestionnaireVersion::active()->cycle;
        $kecLuar = Wilayah::where('level', 'kecamatan')->firstOrFail();
        $desaLuar = Wilayah::where('level', 'desa')->where('parent_code', $kecLuar->code)->firstOrFail();
        $op = User::where('role', 'operator')->firstOrFail();
        $op->update(['wilayah_scope' => Wilayah::where('level', 'kecamatan')
            ->where('code', '!=', $kecLuar->code)->value('code')]);

        $this->actingAs($this->admin())->post('/admin/sampel', [
            'cycle_id' => $cycle->id, 'wilayah_code' => $desaLuar->code,
            'no_bs' => '097X', 'no_ruta' => '097', 'petugas_id' => $op->id,
        ])->assertSessionHasErrors('petugas_id');
        $this->assertDatabaseMissing('sample_households', ['no_bs' => '097X', 'no_ruta' => '097']);
    }

    public function test_allows_petugas_scoped_to_the_matching_kecamatan(): void
    {
        $cycle = QuestionnaireVersion::active()->cycle;
        $kec = Wilayah::where('level', 'kecamatan')->firstOrFail();
        $desa = Wilayah::where('level', 'desa')->where('parent_code', $kec->code)->firstOrFail();
        $op = User::where('role', 'operator')->firstOrFail();
        $op->update(['wilayah_scope' => $kec->code]);

        $this->actingAs($this->admin())->post('/admin/sampel', [
            'cycle_id' => $cycle->id, 'wilayah_code' => $desa->code,
            'no_bs' => '096X', 'no_ruta' => '096', 'petugas_id' => $op->id,
        ])->assertRedirect()->assertSessionHas('ok');
        $this->assertDatabaseHas('sample_households', ['no_bs' => '096X', 'no_ruta' => '096', 'petugas_id' => $op->id]);
    }
}
