<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Document;
use App\Models\Question;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorrectionsExportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_export_lists_cleaning_corrections_with_reason_and_user(): void
    {
        $editor = User::where('username', 'siti_editor')->firstOrFail();
        $doc = Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))
            ->where('status', 'selesai')->firstOrFail();
        $q = Question::whereIn('block_id', Block::where('qversion_id', $doc->qversion_id)->pluck('id'))
            ->where('var_name', 'banyak_art')->firstOrFail();

        $this->actingAs($editor)->post("/pengolahan/{$doc->id}/koreksi", [
            'question_id' => $q->id, 'art_index' => 0, 'clean_value' => '9',
            'reason' => 'Sesuai hasil konfirmasi ulang ke responden.',
        ])->assertRedirect();

        $res = $this->actingAs(User::where('username', 'rahmawati')->firstOrFail())
            ->get('/tabulasi/ekspor-koreksi');
        $res->assertOk();
        $csv = $res->streamedContent();

        $this->assertStringContainsString('banyak_art', $csv);
        $this->assertStringContainsString('konfirmasi ulang ke responden', $csv);
        $this->assertStringContainsString($editor->name, $csv);
        $this->assertStringContainsString('nilai_lama', $csv);   // header
    }

    public function test_export_is_pml_or_admin_only(): void
    {
        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->get('/tabulasi/ekspor-koreksi')->assertForbidden();
    }
}
