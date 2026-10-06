<?php

namespace Tests\Feature;

use App\Models\QuestionnaireVersion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_template_lists_every_import_column_with_roster_suffixes(): void
    {
        // VSEN26.K punya roster ART sungguhan (var r407); VSEN26.KP tidak (Blok III-nya
        // cuma 5 rincian ringkas, bukan roster — Inc 132).
        $qv = QuestionnaireVersion::where('code', 'VSEN26.K')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();

        $res = $this->actingAs($admin)->get("/admin/impor-dokumen/template/{$qv->cycle_id}?art=4");
        $res->assertOk();
        $csv = ltrim($res->streamedContent(), "\xEF\xBB\xBF");
        $header = str_getcsv(explode("\n", trim($csv))[0]);

        // kunci wajib
        foreach (['wilayah_code', 'no_bs', 'no_ruta', 'krt_name'] as $k) {
            $this->assertContains($k, $header);
        }
        // var ruta polos
        $this->assertContains('r301', $header);
        // var roster dengan akhiran __1.._4
        $this->assertContains('r407__1', $header);
        $this->assertContains('r407__4', $header);
        $this->assertNotContains('r407__5', $header);
        // dan kolom yang tidak dikenal importer tidak muncul
        $this->assertNotContains('r407', $header);
    }

    public function test_dictionary_has_one_row_per_question_with_options(): void
    {
        $qv = QuestionnaireVersion::where('code', 'VSEN26.K')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();

        $res = $this->actingAs($admin)->get("/admin/impor-dokumen/kamus/{$qv->cycle_id}");
        $res->assertOk();
        $lines = array_values(array_filter(explode("\n", trim($res->streamedContent()))));
        $this->assertSame('blok,tipe_blok,kode_rincian,var_name,kolom_impor,label,tipe,opsi',
            ltrim($lines[0], "\xEF\xBB\xBF"));

        $body = implode("\n", $lines);
        $this->assertStringContainsString('1=Laki-laki | 2=Perempuan', $body);
        $this->assertStringContainsString('r407__<n>', $body);
    }

    public function test_template_download_is_admin_only(): void
    {
        $qv = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->get("/admin/impor-dokumen/template/{$qv->cycle_id}")
            ->assertForbidden();
    }
}
