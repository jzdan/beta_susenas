<?php

namespace Tests\Feature;

use App\Models\AnswerHistory;
use App\Models\Block;
use App\Models\Document;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntryFlowTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function kpDoc(): Document
    {
        return Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))
            ->where('status', '!=', 'bersih')->firstOrFail();
    }

    private function q(Document $doc, string $var): Question
    {
        return Question::query()
            ->whereIn('block_id', Block::where('qversion_id', $doc->qversion_id)->pluck('id'))
            ->where('var_name', $var)->firstOrFail();
    }

    public function test_operator_saves_answer_and_gets_updated_state(): void
    {
        $doc = $this->kpDoc();
        $operator = User::where('username', 'ppl_ani')->firstOrFail();
        $q = $this->q($doc, 'banyak_art');

        $res = $this->actingAs($operator)->postJson("/entri/{$doc->id}/jawaban", [
            'question_id' => $q->id, 'value' => '5', 'art_index' => 0,
        ]);
        $res->assertOk()->assertJson(['ok' => true, 'saved' => true]);
        $res->assertJsonStructure(['n_err', 'n_warn', 'state' => ['blocks', 'derived', 'n_err', 'n_warn']]);

        $this->assertDatabaseHas('answers', [
            'document_id' => $doc->id, 'question_id' => $q->id, 'art_index' => 0, 'raw_value' => '5',
        ]);
        $this->assertSame(1, AnswerHistory::where('document_id', $doc->id)
            ->where('question_id', $q->id)->count());
    }

    public function test_roster_form_expands_rows_to_banyak_art(): void
    {
        $doc = $this->kpDoc();
        $operator = User::where('username', 'ppl_ani')->firstOrFail();
        $this->actingAs($operator)->postJson("/entri/{$doc->id}/jawaban", [
            'question_id' => $this->q($doc, 'banyak_art')->id, 'value' => '6', 'art_index' => 0,
        ]);

        $state = $this->actingAs($operator)->postJson("/entri/{$doc->id}/jawaban", [
            'question_id' => $this->q($doc, 'art_nama')->id, 'value' => 'Uji', 'art_index' => 3,
        ])->json('state');

        $blockIII = collect($state['blocks'])->firstWhere('code', 'III');
        $this->assertNotNull($blockIII);
        $dataRows = collect($blockIII['rows'])->reject(fn ($r) => isset($r['group_header']));
        $this->assertSame(6, $dataRows->count());
    }

    public function test_list_block_has_group_headers(): void
    {
        $doc = $this->kpDoc();
        $state = $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->postJson("/entri/{$doc->id}/jawaban", [
                'question_id' => $this->q($doc, 'banyak_art')->id, 'value' => '3', 'art_index' => 0,
            ])->json('state');

        $iv1 = collect($state['blocks'])->firstWhere('code', 'IV.1');
        $headers = collect($iv1['rows'])->filter(fn ($r) => isset($r['group_header']));
        $this->assertGreaterThan(5, $headers->count());
        $this->assertContains('PADI-PADIAN', $headers->pluck('group_header')->all());
    }

    public function test_save_blocked_on_clean_document(): void
    {
        $doc = Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))->firstOrFail();
        $doc->update(['status' => 'bersih']);
        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->postJson("/entri/{$doc->id}/jawaban", [
                'question_id' => $this->q($doc, 'banyak_art')->id, 'value' => '2',
            ])->assertStatus(409);
    }

    public function test_viewer_cannot_save_but_may_view(): void
    {
        $doc = $this->kpDoc();
        $viewer = User::where('username', 'viewer')->firstOrFail();
        $this->actingAs($viewer)->get("/entri/{$doc->id}")->assertOk()
            ->assertDontSee('id="next-issue"', false);
        $this->actingAs($viewer)->postJson("/entri/{$doc->id}/jawaban", [
            'question_id' => $this->q($doc, 'banyak_art')->id, 'value' => '2',
        ])->assertForbidden();
    }

    public function test_next_issue_button_shown_to_operator(): void
    {
        $doc = $this->kpDoc();
        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->get("/entri/{$doc->id}")
            ->assertOk()
            ->assertSee('id="next-issue"', false);
    }

    public function test_finalize_refuses_while_errors_remain(): void
    {
        $doc = $this->kpDoc();
        $operator = User::where('username', 'ppl_ani')->firstOrFail();
        // buat error: banyak_art tidak sama dengan jumlah baris roster (III-JML-ART)
        $this->actingAs($operator)->postJson("/entri/{$doc->id}/jawaban", [
            'question_id' => $this->q($doc, 'banyak_art')->id, 'value' => '99', 'art_index' => 0,
        ]);
        $this->actingAs($operator)->post("/entri/{$doc->id}/finalisasi")
            ->assertSessionHasErrors('finalisasi');
        $this->assertNotSame('selesai', $doc->fresh()->status);
    }
}
