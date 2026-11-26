<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Block;
use App\Models\Document;
use App\Models\RuleResult;
use App\Models\User;
use App\Models\ValidationRule;
use App\Services\RuleEngine;
use App\Services\VersionChecker;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Inc 157 — syarat "lewati" per baris di blok anggota rumah tangga (repeat='art'). */
class RowSkipTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    /** @return array{0:Document,1:Block,2:\App\Models\Question,3:\App\Models\Question} */
    private function setup1(): array
    {
        $blk = Block::where('repeat', 'art')->whereHas('qversion', fn ($q) => $q->whereHas('documents'))
            ->get()->first(fn ($b) => ! $b->qversion->locked() && $b->questions->count() >= 2);
        $this->assertNotNull($blk, 'butuh blok anggota rumah tangga dengan sampel');
        $doc = Document::where('qversion_id', $blk->qversion_id)->firstOrFail();
        [$q1, $q2] = [$blk->questions[0], $blk->questions[1]];
        $q2->update(['skip_expr' => "n({$q1->var_name}) == 99"]);

        Answer::where('document_id', $doc->id)->whereIn('question_id', [$q1->id, $q2->id])->delete();
        foreach ([[1, '99'], [2, '1']] as [$art, $v1]) {
            Answer::create(['document_id' => $doc->id, 'question_id' => $q1->id, 'art_index' => $art, 'raw_value' => $v1]);
            Answer::create(['document_id' => $doc->id, 'question_id' => $q2->id, 'art_index' => $art, 'raw_value' => '5']);
        }
        ValidationRule::create([
            'qversion_id' => $blk->qversion_id, 'code' => 'UJI-LEWATI', 'scope' => 'dokumen',
            'severity' => 'error', 'row_block' => $blk->code, 'target_var' => $q2->var_name,
            'expr' => "filled({$q2->var_name})", 'message' => 'uji', 'active' => true,
        ]);

        return [$doc, $blk, $q1, $q2];
    }

    public function test_skipped_cells_are_ignored_by_rules_and_aggregates(): void
    {
        [$doc, , , $q2] = $this->setup1();
        $engine = app(RuleEngine::class);
        $engine->run($doc);

        $rule = ValidationRule::where('code', 'UJI-LEWATI')->firstOrFail();
        $rows = RuleResult::where('document_id', $doc->id)->where('rule_id', $rule->id)->pluck('row_index')->all();
        $this->assertNotContains(1, $rows, 'anggota 1 dilewati → aturan tidak diperiksa di baris itu');
        $this->assertContains(2, $rows);

        $ctx = $engine->buildContext($doc);
        $filled = Answer::where('document_id', $doc->id)->where('question_id', $q2->id)
            ->where('raw_value', '!=', '')->count();
        $this->assertSame((float) ($filled - 1), $ctx["{$q2->var_name}__n"], 'sel yang dilewati tidak ikut dihitung');
        $this->assertTrue(isset($engine->lastSkipped()[$q2->var_name][1]));
    }

    public function test_entry_state_locks_skipped_cell(): void
    {
        [$doc, $blk, $q1, $q2] = $this->setup1();
        $admin = User::where('username', 'admin')->firstOrFail();
        $state = $this->actingAs($admin)->postJson("/entri/{$doc->id}/jawaban", [
            'question_id' => $q1->id, 'value' => '99', 'art_index' => 1,
        ])->assertOk()->json('state');

        $b = collect($state['blocks'])->firstWhere('code', $blk->code);
        $cell = fn ($art, $var) => collect(collect($b['rows'])->firstWhere('idx', $art)['cells'])->firstWhere('var', $var);
        $this->assertTrue($cell(1, $q2->var_name)['skipped']);
        $this->assertFalse($cell(2, $q2->var_name)['skipped']);
        $this->assertFalse($cell(1, $q1->var_name)['skipped']);
    }

    public function test_version_checker_accepts_row_names_in_row_skip(): void
    {
        [$doc, $blk, , $q2] = $this->setup1();
        $q2->update(['skip_expr' => '_row_index > 3']);
        $res = app(VersionChecker::class)->check($blk->qversion);
        $this->assertEmpty(array_filter($res['warnings'], fn ($w) => str_contains($w, $q2->var_name)));
    }
}
