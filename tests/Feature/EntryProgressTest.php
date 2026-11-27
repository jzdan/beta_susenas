<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Document;
use App\Models\Question;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntryProgressTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function kpDoc(): Document
    {
        $doc = Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))
            ->where('status', '!=', 'bersih')->firstOrFail();

        return $this->openForPetugas($doc, User::where('username', 'ppl_ani')->firstOrFail());
    }

    private function q(Document $doc, string $var): Question
    {
        return Question::whereIn('block_id', Block::where('qversion_id', $doc->qversion_id)->pluck('id'))
            ->where('var_name', $var)->firstOrFail();
    }

    public function test_state_reports_progress_and_missing_list(): void
    {
        $doc = $this->kpDoc();
        $operator = User::where('username', 'ppl_ani')->firstOrFail();

        $p = $this->actingAs($operator)->postJson("/entri/{$doc->id}/jawaban", [
            'question_id' => $this->q($doc, 'banyak_art')->id, 'value' => '3', 'art_index' => 0,
        ])->json('state.progress');

        $this->assertIsArray($p);
        $this->assertGreaterThan(0, $p['expected']);
        $this->assertSame($p['pct'], (int) round(100 * $p['filled'] / $p['expected']));
        $this->assertSame(count($p['missing']) <= 60, true);
        $this->assertLessThanOrEqual($p['expected'], $p['filled']);
    }

    public function test_filling_a_required_field_raises_the_count(): void
    {
        $doc = $this->kpDoc();
        $operator = User::where('username', 'ppl_ani')->firstOrFail();

        $before = $this->actingAs($operator)->postJson("/entri/{$doc->id}/jawaban", [
            'question_id' => $this->q($doc, 'banyak_art')->id, 'value' => '', 'art_index' => 0,
        ])->json('state.progress');

        $after = $this->actingAs($operator)->postJson("/entri/{$doc->id}/jawaban", [
            'question_id' => $this->q($doc, 'banyak_art')->id, 'value' => '4', 'art_index' => 0,
        ])->json('state.progress');

        $this->assertSame($before['filled'] + 1, $after['filled']);
        $this->assertSame($before['missing_total'] - 1, $after['missing_total']);
    }

    public function test_progress_bar_renders_on_entry_form(): void
    {
        $doc = $this->kpDoc();
        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->get("/entri/{$doc->id}")
            ->assertOk()
            ->assertSee('Kelengkapan isian')
            ->assertSee('id="entry-progress"', false);
    }
}
