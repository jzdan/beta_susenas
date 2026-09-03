<?php

namespace Tests\Feature;

use App\Models\QuestionnaireVersion;
use App\Models\User;
use App\Services\QuestionnairePackage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionnairePackageTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_export_then_import_reproduces_the_metadata_tree(): void
    {
        $src = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $pkg = app(QuestionnairePackage::class);

        $data = $pkg->export($src);
        $this->assertSame(QuestionnairePackage::FORMAT, $data['format']);

        $dst = $pkg->import($data, $src->cycle_id, 'VSEN26.KP.PKG');

        $this->assertSame($src->blocks()->count(), $dst->blocks()->count());
        $this->assertSame($src->rules()->count(), $dst->rules()->count());
        $this->assertSame($src->parameters()->count(), $dst->parameters()->count());
        $this->assertSame($src->aggregates()->count(), $dst->aggregates()->count());
        $this->assertSame($src->derived()->count(), $dst->derived()->count());

        $srcQ = \App\Models\Question::whereIn('block_id', $src->blocks()->pluck('id'))->count();
        $dstQ = \App\Models\Question::whereIn('block_id', $dst->blocks()->pluck('id'))->count();
        $this->assertSame($srcQ, $dstQ);

        $srcRows = \App\Models\BlockRow::whereIn('block_id', $src->blocks()->pluck('id'))->count();
        $dstRows = \App\Models\BlockRow::whereIn('block_id', $dst->blocks()->pluck('id'))->count();
        $this->assertSame($srcRows, $dstRows);
        $this->assertGreaterThan(0, $dstRows);
    }

    public function test_import_rejects_duplicate_code_and_bad_format(): void
    {
        $pkg = app(QuestionnairePackage::class);
        $data = $pkg->export(QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail());
        $cycle = QuestionnaireVersion::first()->cycle_id;

        $this->expectException(\InvalidArgumentException::class);
        $pkg->import($data, $cycle, 'VSEN26.KP');   // sudah ada
    }

    public function test_artisan_export_and_import_round_trip(): void
    {
        $out = storage_path('app/testpkg.json');
        @unlink($out);

        $this->artisan('susenas:qversion:export', ['code' => 'VSEN26.KP', '--out' => $out])
            ->assertExitCode(0);
        $this->assertFileExists($out);

        $this->artisan('susenas:qversion:import', ['file' => $out, '--code' => 'VSEN26.KP.CLI'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('questionnaire_versions', ['code' => 'VSEN26.KP.CLI']);
        @unlink($out);
    }

    public function test_lint_flags_broken_expression_and_missing_row_block(): void
    {
        $pkg = app(QuestionnairePackage::class);
        $data = $pkg->export(QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail());
        $data['code'] = 'VSEN26.KP.LINT';
        $data['rules'][] = ['code' => 'BUSTED', 'scope' => 'dokumen', 'row_block' => 'ZZZ',
            'target_var' => '', 'expr' => 'n(a) and and (', 'severity' => 'warning', 'message' => 'x'];

        $lint = $pkg->lint($data);
        $joined = implode(' | ', $lint['errors']);
        $this->assertStringContainsString('BUSTED', $joined);
        $this->assertStringContainsString('ZZZ', $joined);
    }

    public function test_clean_package_passes_lint(): void
    {
        $pkg = app(QuestionnairePackage::class);
        $data = $pkg->export(QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail());
        $data['code'] = 'VSEN26.KP.OK';

        $this->assertSame([], $pkg->lint($data)['errors']);
    }

    public function test_web_import_checks_then_commits(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $src = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $json = json_encode(app(QuestionnairePackage::class)->export($src));

        // mode check: tidak mengimpor
        $this->actingAs($admin)->post('/admin/versi-impor', [
            'data' => $json, 'cycle_id' => $src->cycle_id, 'code' => 'VSEN26.KP.WEB', 'mode' => 'check',
        ])->assertOk();
        $this->assertDatabaseMissing('questionnaire_versions', ['code' => 'VSEN26.KP.WEB']);

        // mode commit
        $this->actingAs($admin)->post('/admin/versi-impor', [
            'data' => $json, 'cycle_id' => $src->cycle_id, 'code' => 'VSEN26.KP.WEB', 'mode' => 'commit', 'force' => '1',
        ])->assertRedirect(route('admin.metadata'));
        $this->assertDatabaseHas('questionnaire_versions', ['code' => 'VSEN26.KP.WEB']);
    }

    public function test_admin_can_download_json_package(): void
    {
        $qv = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $res = $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->get("/admin/versi/{$qv->id}/ekspor");

        $res->assertOk();
        $res->assertHeader('content-type', 'application/json');
        $this->assertSame(QuestionnairePackage::FORMAT, $res->json('format'));
    }
}
