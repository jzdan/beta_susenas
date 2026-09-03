<?php

namespace Tests\Feature;

use App\Models\QuestionnaireVersion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CodebookTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_codebook_lists_blocks_variables_and_rules(): void
    {
        $qv = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();

        $res = $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->get("/admin/versi/{$qv->id}/kodebook");

        $res->assertOk()
            ->assertSee('Buku Kode')
            ->assertSee('VSEN26.KP')
            ->assertSee('Aturan validasi')
            ->assertSee('banyak_art')
            ->assertSee('Nilai turunan');
    }

    public function test_codebook_is_admin_only(): void
    {
        $qv = QuestionnaireVersion::first();
        $this->actingAs(User::where('username', 'siti_editor')->firstOrFail())
            ->get("/admin/versi/{$qv->id}/kodebook")->assertForbidden();
    }
}
