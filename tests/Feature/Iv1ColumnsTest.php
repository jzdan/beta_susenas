<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Block;
use App\Models\Document;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\RuleResult;
use App\Services\RuleEngine;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Iv1ColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function iv1(): Block
    {
        return Block::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))
            ->where('code', 'IV.1')->firstOrFail();
    }

    public function test_block_has_full_column_set(): void
    {
        $vars = $this->iv1()->questions->pluck('var_name')->all();
        foreach (['iv1_kuantitas_beli', 'iv1_nilai_beli', 'iv1_kuantitas_lain', 'iv1_nilai_lain',
            'iv1_asal_lain', 'iv1_kuantitas', 'iv1_nilai'] as $v) {
            $this->assertContains($v, $vars, "kolom {$v} harus ada di Blok IV.1");
        }
    }

    private function setRow(Document $doc, Block $b, int $row, array $vals): void
    {
        foreach ($vals as $var => $raw) {
            $q = Question::where('block_id', $b->id)->where('var_name', $var)->firstOrFail();
            Answer::updateOrCreate(
                ['document_id' => $doc->id, 'question_id' => $q->id, 'art_index' => $row],
                ['raw_value' => (string) $raw],
            );
        }
    }

    private function fired(Document $doc, int $row, string $code): bool
    {
        return RuleResult::where('document_id', $doc->id)->where('row_index', $row)
            ->whereIn('status', ['error', 'warning'])
            ->whereHas('rule', fn ($q) => $q->where('code', $code))->exists();
    }

    public function test_jumlah_konsumsi_crosschecks(): void
    {
        $qv = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $doc = Document::where('qversion_id', $qv->id)->firstOrFail();
        $b = $this->iv1();
        $engine = app(RuleEngine::class);

        // konsisten: (9) = (5)+(7), (10) = (6)+(8) -> tidak menyala
        $this->setRow($doc, $b, 1, [
            'iv1_kuantitas_beli' => '8', 'iv1_nilai_beli' => '80000',
            'iv1_kuantitas_lain' => '2', 'iv1_nilai_lain' => '20000',
            'iv1_kuantitas' => '10', 'iv1_nilai' => '100000',
        ]);
        $engine->run($doc);
        $this->assertFalse($this->fired($doc, 1, 'IV1-JUMLAH-BANYAK'));
        $this->assertFalse($this->fired($doc, 1, 'IV1-JUMLAH-NILAI'));

        // tidak konsisten pada banyak & nilai
        $this->setRow($doc, $b, 1, ['iv1_kuantitas' => '99', 'iv1_nilai' => '999999']);
        $engine->run($doc);
        $this->assertTrue($this->fired($doc, 1, 'IV1-JUMLAH-BANYAK'));
        $this->assertTrue($this->fired($doc, 1, 'IV1-JUMLAH-NILAI'));
    }
}
