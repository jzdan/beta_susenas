<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Block;
use App\Models\Document;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintViewTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function kpDoc(): Document
    {
        return Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))->firstOrFail();
    }

    public function test_print_view_renders_all_blocks_for_any_authenticated_role(): void
    {
        $doc = $this->kpDoc();
        foreach (['viewer', 'ppl_ani', 'rahmawati', 'admin'] as $uname) {
            $this->actingAs(User::where('username', $uname)->firstOrFail())
                ->get("/entri/{$doc->id}/cetak")
                ->assertOk()
                ->assertSee('Salinan Kuesioner SUSENAS')
                ->assertSee('Blok I —', false)
                ->assertSee('Temuan validasi', false);
        }
    }

    public function test_print_view_shows_clean_value_alongside_raw(): void
    {
        $doc = $this->kpDoc();
        $b = Block::where('qversion_id', $doc->qversion_id)->where('code', 'IV.1')->firstOrFail();
        $q = Question::where('block_id', $b->id)->where('var_name', 'iv1_kuantitas')->firstOrFail();
        Answer::updateOrCreate(
            ['document_id' => $doc->id, 'question_id' => $q->id, 'art_index' => 1],
            ['raw_value' => '450', 'clean_value' => '45'],
        );

        $this->actingAs(User::where('username', 'rahmawati')->firstOrFail())
            ->get("/entri/{$doc->id}/cetak")
            ->assertOk()
            ->assertSee('mentah: 450');   // koreksi ditampilkan bersama nilai mentah
    }

    public function test_print_view_includes_cleaning_trail_and_notes(): void
    {
        $doc = Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))
            ->where('status', 'selesai')->firstOrFail();
        $editor = User::where('username', 'siti_editor')->firstOrFail();
        $q = Question::whereIn('block_id', Block::where('qversion_id', $doc->qversion_id)->pluck('id'))
            ->where('var_name', 'banyak_art')->firstOrFail();

        $this->actingAs($editor)->post("/pengolahan/{$doc->id}/koreksi", [
            'question_id' => $q->id, 'art_index' => 0, 'clean_value' => '7',
            'reason' => 'Angka tertukar dengan nomor urut.',
        ])->assertRedirect();
        \App\Models\DocumentNote::create([
            'document_id' => $doc->id, 'body' => 'Sudah dikonfirmasi via telepon.', 'by_user' => $editor->id,
        ]);

        $this->actingAs(User::where('username', 'rahmawati')->firstOrFail())
            ->get("/entri/{$doc->id}/cetak")
            ->assertOk()
            ->assertSee('Jejak koreksi pengolahan')
            ->assertSee('Angka tertukar dengan nomor urut')
            ->assertSee('Catatan isian')
            ->assertSee('dikonfirmasi via telepon');
    }

    public function test_empty_roster_rows_are_omitted(): void
    {
        $doc = $this->kpDoc();
        $blocks = $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->get("/entri/{$doc->id}/cetak")->viewData('blocks');

        $iv1 = collect($blocks)->firstWhere('code', 'IV.1');
        // hanya komoditas yang terisi yang muncul (jauh lebih sedikit dari 194)
        $this->assertLessThan(30, count($iv1['rows']));
        $this->assertGreaterThan(0, count($iv1['rows']));
    }
}
