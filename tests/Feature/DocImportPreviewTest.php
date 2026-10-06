<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\QuestionnaireVersion;
use App\Models\SampleHousehold;
use App\Models\SurveyCycle;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function korCycleId(): int
    {
        return QuestionnaireVersion::where('code', 'VSEN26.K')->firstOrFail()->cycle_id;
    }

    public function test_preview_does_not_persist_but_reports_mapping(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $cycleId = $this->korCycleId();
        $before = Document::count();

        $csv = "wilayah_code,no_bs,no_ruta,krt_name,r301,r403__1,kolom_ngawur\n"
            . "3526010001,900A,001,Uji Pratinjau,2,1,999\n";

        $res = $this->actingAs($admin)->post('/admin/impor-dokumen', [
            'cycle_id' => $cycleId, 'data' => $csv, 'mode' => 'preview',
        ]);
        $res->assertOk();

        $h = $res->viewData('hasil');
        $this->assertTrue($h['preview']);
        $this->assertSame(1, $h['dibuat']);
        $this->assertArrayHasKey('kolom_ngawur', $h['unknown']);
        $this->assertContains('r301', $h['used']);

        // tidak ada yang tersimpan
        $this->assertSame($before, Document::count());
        $this->assertNull(SampleHousehold::where('no_bs', '900A')->first());
    }

    public function test_commit_persists_rows(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $cycleId = $this->korCycleId();

        $csv = "wilayah_code,no_bs,no_ruta,krt_name,r301\n3526010001,901A,001,Uji Commit,3\n";
        $res = $this->actingAs($admin)->post('/admin/impor-dokumen', [
            'cycle_id' => $cycleId, 'data' => $csv, 'mode' => 'commit',
        ]);
        $res->assertOk();

        $s = SampleHousehold::where('no_bs', '901A')->first();
        $this->assertNotNull($s);
        $this->assertNotNull(Document::where('sample_id', $s->id)->first());
    }
}
