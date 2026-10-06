<?php

namespace Tests\Feature;

use App\Models\ColumnAlias;
use App\Models\QuestionnaireVersion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ColumnAliasTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_admin_saves_aliases_in_bulk(): void
    {
        $qv = QuestionnaireVersion::where('code', 'VSEN26.K')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)->post('/admin/impor-dokumen/alias', [
            'cycle_id' => $qv->cycle_id,
            'bulk' => "KRT_UMUR_1,r407__1\nKRT_UMUR_2,r407__2\nJML_ART,r301",
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertSame(3, ColumnAlias::where('qversion_id', $qv->id)->count());
        $this->assertSame('r301', ColumnAlias::mapFor($qv->id)['JML_ART']);
    }

    public function test_import_translates_aliased_columns(): void
    {
        $qv = QuestionnaireVersion::where('code', 'VSEN26.K')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        ColumnAlias::create(['qversion_id' => $qv->id, 'source_col' => 'JML_ART', 'target_col' => 'r301']);
        ColumnAlias::create(['qversion_id' => $qv->id, 'source_col' => 'UMUR_KRT', 'target_col' => 'r407__1']);

        $csv = "wilayah_code,no_bs,no_ruta,krt_name,JML_ART,UMUR_KRT,KOLOM_NGAWUR\n"
            . "3526010001,900Z,001,Uji Alias,3,44,x\n";

        $res = $this->actingAs($admin)->post('/admin/impor-dokumen', [
            'cycle_id' => $qv->cycle_id, 'data' => $csv, 'mode' => 'preview',
        ])->assertOk();

        $h = $res->viewData('hasil');
        // kolom yang dialiaskan tidak masuk daftar "tidak dikenal"
        $this->assertArrayNotHasKey('JML_ART', $h['unknown']);
        $this->assertArrayNotHasKey('UMUR_KRT', $h['unknown']);
        $this->assertArrayHasKey('KOLOM_NGAWUR', $h['unknown']);
        // dan tercatat sebagai dipakai lewat alias
        $this->assertArrayHasKey('JML_ART', $h['alias_used']);
        $this->assertContains('r301', $h['used']);
        $this->assertContains('r407', $h['used']);
    }
}
