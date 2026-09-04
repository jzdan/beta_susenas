<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Block;
use App\Models\Document;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\ReferencePrice;
use App\Models\RuleResult;
use App\Models\User;
use App\Services\RuleEngine;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferencePriceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function kpDoc(): array
    {
        $qv = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $doc = Document::where('qversion_id', $qv->id)->firstOrFail();
        $b = Block::where('qversion_id', $qv->id)->where('code', 'IV.1')->firstOrFail();

        return [$qv, $doc, $b];
    }

    private function setBeras(Document $doc, Block $b, string $kuant, string $nilai): void
    {
        $qk = Question::where('block_id', $b->id)->where('var_name', 'iv1_kuantitas')->firstOrFail();
        $qn = Question::where('block_id', $b->id)->where('var_name', 'iv1_nilai_beli')->firstOrFail();
        Answer::updateOrCreate(['document_id' => $doc->id, 'question_id' => $qk->id, 'art_index' => 1], ['raw_value' => $kuant]);
        Answer::updateOrCreate(['document_id' => $doc->id, 'question_id' => $qn->id, 'art_index' => 1], ['raw_value' => $nilai]);
    }

    private function hargaFired(Document $doc): bool
    {
        return RuleResult::where('document_id', $doc->id)->where('row_index', 1)
            ->whereIn('status', ['error', 'warning'])
            ->whereHas('rule', fn ($q) => $q->where('code', 'IV1-HARGA'))->exists();
    }

    public function test_wilayah_specific_range_is_stricter_than_national(): void
    {
        [$qv, $doc, $b] = $this->kpDoc();
        $engine = app(RuleEngine::class);

        // beras 10 kg / Rp 200.000 -> harga satuan 20.000
        // nasional (10rb-25rb): lolos ; Bangkalan seed (11rb-15rb): menyala
        $this->setBeras($doc, $b, '10', '200000');
        $engine->run($doc);
        $this->assertTrue($this->hargaFired($doc), 'seharusnya melanggar rentang Bangkalan');

        // hapus override wilayah 3526 -> kembali pakai nasional -> tidak menyala lagi
        ReferencePrice::where('qversion_id', $qv->id)->where('code', '002')->where('wilayah_prefix', '3526')->delete();
        $engine->run($doc);
        $this->assertFalse($this->hargaFired($doc), 'tanpa override wilayah, 20rb masih di dalam rentang nasional');
    }

    public function test_max_kons_rule_flags_excessive_quantity(): void
    {
        [$qv, $doc, $b] = $this->kpDoc();
        $engine = app(RuleEngine::class);

        // baris 1 = Beras, ref max_kons = 7 (per ruta/minggu)
        $br = \App\Models\BlockRow::where('block_id', $b->id)->where('row_index', 1)->firstOrFail();
        $this->assertArrayHasKey('max_kons', $br->ref, 'seed harus memuat max_kons');

        $fired = fn () => RuleResult::where('document_id', $doc->id)->where('row_index', 1)
            ->whereIn('status', ['error', 'warning'])
            ->whereHas('rule', fn ($q) => $q->where('code', 'IV1-KUANTITAS-MAX'))->exists();

        $this->setBeras($doc, $b, '5', '60000');   // 5 kg <= 7 -> aman
        $engine->run($doc);
        $this->assertFalse($fired());

        $this->setBeras($doc, $b, '40', '480000'); // 40 kg > 7 -> menyala
        $engine->run($doc);
        $this->assertTrue($fired());
    }

    public function test_admin_imports_reference_prices(): void
    {
        $qv = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)->post('/admin/harga', [
            'cycle_id' => $qv->cycle_id,
            'data' => "code,wilayah_prefix,harga_min,harga_max\n002,35,12000,18000\n999,,5,3\n",
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertDatabaseHas('reference_prices', [
            'qversion_id' => $qv->id, 'code' => '002', 'wilayah_prefix' => '35', 'harga_min' => 12000,
        ]);
        // baris harga_min > harga_max dilewati
        $this->assertDatabaseMissing('reference_prices', ['code' => '999']);
    }
}
