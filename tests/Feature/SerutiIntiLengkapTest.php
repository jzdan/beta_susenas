<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\QuestionnaireVersion;
use App\Models\User;
use App\Models\ValidationRule;
use App\Services\VersionChecker;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SerutiIntiLengkapSeeder;
use Database\Seeders\SerutiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Inc 156 — SERUTI INTI lengkap (Blok III, IV.2.B, IV.3, V 501–505, VI, VII) dari PDF VSERUTI26.INTI. */
class SerutiIntiLengkapTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function inti(): QuestionnaireVersion
    {
        $this->seed(SerutiSeeder::class);

        return QuestionnaireVersion::where('code', 'VSERUTI26.INTI')->firstOrFail();
    }

    public function test_all_blocks_are_built_in_questionnaire_order_and_intact(): void
    {
        $qv = $this->inti();
        $this->assertSame(['POKOK', 'III', 'IV2A', 'IV2B', 'IV3', 'V1', 'V2', 'V3', 'V4', 'V5', 'VI', 'VII'],
            Block::where('qversion_id', $qv->id)->orderBy('order')->pluck('code')->all());
        $this->assertSame([], app(VersionChecker::class)->check($qv)['errors']);

        $b = Block::where('qversion_id', $qv->id)->where('code', 'IV2B')->firstOrFail();
        $this->assertSame(12, $b->rows()->count());
        $this->assertSame('total', $b->rows()->where('code', '463')->first()->ref['level']);
        $this->assertTrue(ValidationRule::where('qversion_id', $qv->id)->where('code', 'SRT-IV2B-TOTAL')->exists());
    }

    public function test_seeder_is_idempotent(): void
    {
        $qv = $this->inti();
        $before = Block::where('qversion_id', $qv->id)->count();
        $this->seed(SerutiIntiLengkapSeeder::class);
        $this->assertSame($before, Block::where('qversion_id', $qv->id)->count());
    }

    public function test_entry_preview_renders_the_new_blocks(): void
    {
        $qv = $this->inti();
        $this->actingAs(User::where('username', 'admin')->firstOrFail())->get("/admin/pratinjau/{$qv->id}")->assertOk()
            ->assertSee('Pengeluaran Kesehatan dan Pendidikan')->assertSee('Posisi Aset dan Kewajiban')
            ->assertSee('Persepsi terhadap Kondisi Ekonomi');
    }
}
