<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\BlockRow;
use App\Models\Document;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\RuleResult;
use App\Models\User;
use App\Services\RuleEngine;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SerutiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerutiModuleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        // SERUTI ditunda dari seed default — dipanggil eksplisit di sini
        $this->seed(SerutiSeeder::class);
    }

    private function qv(): QuestionnaireVersion
    {
        return QuestionnaireVersion::where('code', 'VSERUTI26.INTI')->firstOrFail();
    }

    public function test_seruti_version_is_seeded_with_iv2a_block(): void
    {
        $qv = $this->qv();
        $b = Block::where('qversion_id', $qv->id)->where('code', 'IV2A')->firstOrFail();

        $this->assertSame('list', $b->repeat);
        $this->assertSame(53, BlockRow::where('block_id', $b->id)->count());
        $this->assertDatabaseHas('block_rows', ['block_id' => $b->id, 'code' => '451']);
        $this->assertDatabaseHas('questions', ['block_id' => $b->id, 'var_name' => 'siv2a_sebulan']);
    }

    public function test_total_mismatch_rule_fires_and_matching_total_is_clean(): void
    {
        $qv = $this->qv();
        $engine = app(RuleEngine::class);

        $rowByCode = BlockRow::whereIn('block_id', Block::where('qversion_id', $qv->id)->pluck('id'))
            ->pluck('row_index', 'code');
        $sebulan = Question::whereIn('block_id', Block::where('qversion_id', $qv->id)->pluck('id'))
            ->where('var_name', 'siv2a_sebulan')->firstOrFail();

        $sample = \App\Models\SampleHousehold::create([
            'cycle_id' => $qv->cycle_id, 'wilayah_code' => '3526010001', 'no_bs' => 'S999',
            'no_ruta' => '009', 'krt_name' => 'Uji', 'status' => 'selesai',
        ]);
        $doc = Document::create(['sample_id' => $sample->id, 'qversion_id' => $qv->id, 'status' => 'selesai']);

        $put = fn (string $code, $val) => \App\Models\Answer::create([
            'document_id' => $doc->id, 'question_id' => $sebulan->id,
            'art_index' => (int) $rowByCode[$code], 'raw_value' => (string) $val,
        ]);
        $put('402', 100000);
        $put('403', 50000);
        $put('451', 999999);   // jauh dari 150.000

        $engine->run($doc);
        $codes = RuleResult::where('document_id', $doc->id)->whereIn('status', ['error', 'warning'])
            ->with('rule')->get()->map(fn ($r) => $r->rule->code);
        $this->assertContains('SRT-IV2A-TOTAL-1', $codes->all());

        // perbaiki total -> tidak lagi menyala
        \App\Models\Answer::where('document_id', $doc->id)->where('art_index', (int) $rowByCode['451'])
            ->update(['raw_value' => '150000']);
        $engine->run($doc);
        $codes2 = RuleResult::where('document_id', $doc->id)->whereIn('status', ['error', 'warning'])
            ->with('rule')->get()->map(fn ($r) => $r->rule->code);
        $this->assertNotContains('SRT-IV2A-TOTAL-1', $codes2->all());
    }

    public function test_entry_form_renders_for_a_seruti_document(): void
    {
        $doc = Document::where('qversion_id', $this->qv()->id)->where('status', '!=', 'bersih')->firstOrFail();

        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->get("/entri/{$doc->id}")
            ->assertOk()
            ->assertSee('IV2A')
            ->assertSee('Pengeluaran sebulan terakhir');
    }
}
