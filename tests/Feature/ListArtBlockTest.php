<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Block;
use App\Models\Document;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\RuleResult;
use App\Models\User;
use App\Services\RuleEngine;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListArtBlockTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function iv1m(): Block
    {
        return Block::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))
            ->where('code', 'IV.1M')->firstOrFail();
    }

    public function test_pack_unpack_row_art_roundtrips(): void
    {
        $this->assertSame(196002, Block::packRowArt(196, 2));
        $this->assertSame([196, 2], Block::unpackRowArt(196002));
        $this->assertSame([225, 15], Block::unpackRowArt(Block::packRowArt(225, 15)));
    }

    public function test_seeded_block_is_list_art_with_commodity_rows(): void
    {
        $b = $this->iv1m();
        $this->assertSame('list_art', $b->repeat);
        $this->assertTrue($b->isRowBlock());
        $this->assertGreaterThanOrEqual(4, $b->rows()->count());
        $this->assertDatabaseHas('block_rows', ['block_id' => $b->id, 'row_index' => 196]); // nasi rames
    }

    public function test_per_commodity_per_art_rule_fires_with_row_ref(): void
    {
        $b = $this->iv1m();
        $doc = $this->openForPetugas(
            Document::where('qversion_id', $b->qversion_id)->where('status', '!=', 'bersih')->firstOrFail(),
            User::where('username', 'ppl_ani')->firstOrFail());
        $qSumber = Question::where('block_id', $b->id)->where('var_name', 'mj_sumber')->firstOrFail();
        $qKuant = Question::where('block_id', $b->id)->where('var_name', 'mj_kuantitas')->firstOrFail();

        // ART 3 makan nasi rames (196) dari MBG, tanpa kuantitas -> IV1M-MBG-KUANTITAS
        $idx = Block::packRowArt(196, 3);
        Answer::create(['document_id' => $doc->id, 'question_id' => $qSumber->id, 'art_index' => $idx, 'raw_value' => '4']);
        app(RuleEngine::class)->run($doc);

        $hit = RuleResult::where('document_id', $doc->id)->where('row_index', $idx)
            ->whereIn('status', ['error', 'warning'])
            ->whereHas('rule', fn ($q) => $q->where('code', 'IV1M-MBG-KUANTITAS'))->exists();
        $this->assertTrue($hit);

        // isi kuantitas -> tidak menyala lagi
        Answer::create(['document_id' => $doc->id, 'question_id' => $qKuant->id, 'art_index' => $idx, 'raw_value' => '1']);
        app(RuleEngine::class)->run($doc);
        $hit2 = RuleResult::where('document_id', $doc->id)->where('row_index', $idx)
            ->whereIn('status', ['error', 'warning'])
            ->whereHas('rule', fn ($q) => $q->where('code', 'IV1M-MBG-KUANTITAS'))->exists();
        $this->assertFalse($hit2);
    }

    public function test_saving_a_list_art_cell_keeps_the_packed_index(): void
    {
        $b = $this->iv1m();
        $doc = $this->openForPetugas(
            Document::where('qversion_id', $b->qversion_id)->where('status', '!=', 'bersih')->firstOrFail(),
            User::where('username', 'ppl_ani')->firstOrFail());
        $q = Question::where('block_id', $b->id)->where('var_name', 'mj_kuantitas')->firstOrFail();

        // komoditas 217 (Es krim) × ART 4 -> art_index 217004, dulu ke-clamp ke 500
        $idx = Block::packRowArt(217, 4);
        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->postJson("/entri/{$doc->id}/jawaban", [
                'question_id' => $q->id, 'value' => '2', 'art_index' => $idx,
            ])->assertOk();

        $this->assertDatabaseHas('answers', [
            'document_id' => $doc->id, 'question_id' => $q->id, 'art_index' => 217004, 'raw_value' => '2',
        ]);
    }

    public function test_set_art_count_shrinks_and_drops_orphaned_answers(): void
    {
        $b = $this->iv1m();
        $doc = $this->openForPetugas(
            Document::where('qversion_id', $b->qversion_id)->where('status', '!=', 'bersih')->firstOrFail(),
            User::where('username', 'ppl_ani')->firstOrFail());
        $qKuant = Question::where('block_id', $b->id)->where('var_name', 'mj_kuantitas')->firstOrFail();
        $op = User::where('username', 'ppl_ani')->firstOrFail();

        // isian di ART 16 (list_art) — VSEN26.KP tidak punya blok roster ('art') lagi
        // sejak Inc 132 (Blok III bukan roster); pengujian shrink untuk roster ada di K.
        Answer::create(['document_id' => $doc->id, 'question_id' => $qKuant->id,
            'art_index' => Block::packRowArt(196, 16), 'raw_value' => '3']);

        $this->actingAs($op)->postJson("/entri/{$doc->id}/jumlah-art", ['count' => 10])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame('10', Answer::where('document_id', $doc->id)
            ->whereHas('question', fn ($q) => $q->where('var_name', 'banyak_art'))->value('raw_value'));
        $this->assertDatabaseMissing('answers', ['document_id' => $doc->id, 'art_index' => Block::packRowArt(196, 16)]);
    }

    /** Sisi roster ('art'-repeat, bukan list_art) dari shrink jumlah ART — diuji di VSEN26.K. */
    public function test_set_art_count_shrinks_roster_answers_on_kor(): void
    {
        $qv = QuestionnaireVersion::where('code', 'VSEN26.K')->firstOrFail();
        $op = User::where('username', 'ppl_ani')->firstOrFail();
        $doc = $this->openForPetugas(
            Document::where('qversion_id', $qv->id)->where('status', '!=', 'bersih')->firstOrFail(), $op);
        $bArt = Block::where('qversion_id', $qv->id)->where('code', 'ART')->firstOrFail();
        $qArtRoster = Question::where('block_id', $bArt->id)->where('var_name', 'r407')->firstOrFail();

        Answer::updateOrCreate(['document_id' => $doc->id, 'question_id' => $qArtRoster->id, 'art_index' => 12],
            ['raw_value' => '30']);

        $this->actingAs($op)->postJson("/entri/{$doc->id}/jumlah-art", ['count' => 10])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame('10', Answer::where('document_id', $doc->id)
            ->whereHas('question', fn ($q) => $q->where('var_name', 'r301'))->value('raw_value'));
        $this->assertDatabaseMissing('answers', ['document_id' => $doc->id, 'question_id' => $qArtRoster->id, 'art_index' => 12]);
    }

    public function test_entry_form_follows_banyak_art_up_to_the_cap(): void
    {
        $b = $this->iv1m();
        $doc = $this->openForPetugas(
            Document::where('qversion_id', $b->qversion_id)->where('status', '!=', 'bersih')->firstOrFail(),
            User::where('username', 'ppl_ani')->firstOrFail());
        $qArt = Question::whereIn('block_id', Block::where('qversion_id', $b->qversion_id)->pluck('id'))
            ->where('var_name', 'banyak_art')->firstOrFail();

        $state = $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->postJson("/entri/{$doc->id}/jawaban", ['question_id' => $qArt->id, 'value' => '18'])
            ->json('state');
        $blk = collect($state['blocks'])->firstWhere('code', 'IV.1M');
        $dataRows = collect($blk['rows'])->reject(fn ($r) => isset($r['group_header']));

        // 18 <= 30 (cap) -> (jumlah komoditas) × 18 ART
        $nKom = $b->rows()->count();
        $this->assertSame($nKom * 18, $dataRows->count());
    }

    public function test_apply_art_button_works_across_questionnaires(): void
    {
        $op = User::where('username', 'ppl_ani')->firstOrFail();

        foreach (['VSEN26.KP' => 'banyak_art', 'VSEN26.K' => 'r301', 'VSEN'.now()->format('y').'.KP-CONTOH' => 'banyak_art'] as $code => $var) {
            $qv = QuestionnaireVersion::where('code', $code)->firstOrFail();
            $doc = Document::where('qversion_id', $qv->id)->where('status', '!=', 'bersih')->first();
            if (! $doc) {
                continue;
            }
            // petugas hanya bisa mengisi selama periode lapangan siklusnya dibuka
            $doc = $this->openForPetugas($doc, $op);

            $res = $this->actingAs($op)->get("/entri/{$doc->id}")->assertOk();
            $this->assertSame($var, $res->viewData('state')['art_var'], "art_var untuk {$code}");
            $res->assertSee('id="apply-art"', false);

            $this->actingAs($op)->postJson("/entri/{$doc->id}/jumlah-art", ['count' => 5])
                ->assertOk()->assertJson(['ok' => true]);
            $this->assertSame('5', Answer::where('document_id', $doc->id)
                ->whereHas('question', fn ($q) => $q->where('var_name', $var))->value('raw_value'));
        }
    }

    public function test_iv1m_kalori_and_belanja_feed_into_weekly_totals(): void
    {
        // Inc 129 — sebelumnya Blok IV.1M (makanan jadi & rokok) divalidasi per rincian tapi
        // TIDAK ikut kalori_total/belanja mingguan (bug: computeAggregate belum membongkar
        // art_index terpaket row*1000+art punya list_art, jadi ref.kalori/nilai tak pernah kena).
        $b = $this->iv1m();
        $doc = $this->openForPetugas(
            Document::where('qversion_id', $b->qversion_id)->where('status', '!=', 'bersih')->firstOrFail(),
            User::where('username', 'ppl_ani')->firstOrFail());
        $row = $b->rows()->where('row_index', 188)->firstOrFail(); // Roti tawar
        $kalori = (float) $row->ref['kalori'];
        $this->assertGreaterThan(0, $kalori, 'baris uji harus punya ref.kalori');

        $qArt = Question::whereIn('block_id', Block::where('qversion_id', $b->qversion_id)->pluck('id'))
            ->where('var_name', 'banyak_art')->firstOrFail();
        Answer::updateOrCreate(['document_id' => $doc->id, 'question_id' => $qArt->id, 'art_index' => 0], ['raw_value' => '2']);

        $engine = app(RuleEngine::class);
        $before = $engine->buildContext($doc);
        $kaloriBefore = (float) ($before['kalori_total'] ?? 0);

        $qKuant = Question::where('block_id', $b->id)->where('var_name', 'mj_kuantitas')->firstOrFail();
        $qNilai = Question::where('block_id', $b->id)->where('var_name', 'mj_nilai')->firstOrFail();
        $idx = Block::packRowArt(188, 1);
        Answer::create(['document_id' => $doc->id, 'question_id' => $qKuant->id, 'art_index' => $idx, 'raw_value' => '4']);
        Answer::create(['document_id' => $doc->id, 'question_id' => $qNilai->id, 'art_index' => $idx, 'raw_value' => '20000']);

        $after = $engine->buildContext($doc);
        $this->assertEqualsWithDelta(4 * $kalori, $after['iv1m_kalori_minggu'], 0.01);
        $this->assertEquals(20000.0, $after['iv1m_belanja_minggu']);
        $this->assertEqualsWithDelta(
            round($kaloriBefore + 4 * $kalori),
            $after['kalori_total_gabungan'],
            1.0,
        );
        $expectedPerKapita = round(($after['kalori_total_gabungan']) / 2 / 7, 0);
        $this->assertEquals($expectedPerKapita, $after['kalori_per_kapita_hari']);
    }

    public function test_entry_form_renders_list_art_block(): void
    {
        $b = $this->iv1m();
        $doc = $this->openForPetugas(
            Document::where('qversion_id', $b->qversion_id)->where('status', '!=', 'bersih')->firstOrFail(),
            User::where('username', 'ppl_ani')->firstOrFail());
        // set banyak_art supaya ada baris ART
        $qArt = Question::whereIn('block_id', Block::where('qversion_id', $b->qversion_id)->pluck('id'))
            ->where('var_name', 'banyak_art')->firstOrFail();
        Answer::updateOrCreate(['document_id' => $doc->id, 'question_id' => $qArt->id, 'art_index' => 0], ['raw_value' => '3']);

        $state = $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->postJson("/entri/{$doc->id}/jawaban", ['question_id' => $qArt->id, 'value' => '3'])
            ->json('state');

        $blk = collect($state['blocks'])->firstWhere('code', 'IV.1M');
        $this->assertNotNull($blk);
        $this->assertSame('list_art', $blk['repeat']);
        // (jumlah komoditas) × N ART baris data (+ 1 group header per komoditas)
        $nKom = $b->rows()->count();
        $dataRows = collect($blk['rows'])->reject(fn ($r) => isset($r['group_header']));
        $this->assertGreaterThanOrEqual($nKom * 3, $dataRows->count());
        $this->assertSame(0, $dataRows->count() % $nKom);
        $this->assertSame($nKom, collect($blk['rows'])->filter(fn ($r) => isset($r['group_header']))->count());
    }
}
