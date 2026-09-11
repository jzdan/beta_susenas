<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Block;
use App\Models\BlockRow;
use App\Models\Document;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
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
            ->whereHas('rule', fn ($q) => $q->whereIn('code', ['IV1-HARGA-MAHAL', 'IV1-HARGA-MURAH']))->exists();
    }

    private function hargaResult(Document $doc, string $code): ?RuleResult
    {
        return RuleResult::where('document_id', $doc->id)->where('row_index', 1)
            ->whereHas('rule', fn ($q) => $q->where('code', $code))
            ->whereIn('status', ['error', 'warning'])->first();
    }

    public function test_price_rule_uses_commodity_range_from_block_rows(): void
    {
        [$qv, $doc, $b] = $this->kpDoc();
        $engine = app(RuleEngine::class);

        // baris 1 = Beras, ref harga 11.000–15.000 (disetel di seeder)
        $br = BlockRow::where('block_id', $b->id)->where('row_index', 1)->firstOrFail();
        $this->assertSame('11000', (string) ($br->ref['harga_min'] ?? null));

        // 10 kg / Rp 200.000 -> 20.000/kg -> di luar rentang -> menyala
        $this->setBeras($doc, $b, '10', '200000');
        $engine->run($doc);
        $this->assertTrue($this->hargaFired($doc));

        // longgarkan rentang -> tidak menyala lagi
        $br->update(['ref' => array_merge((array) $br->ref, ['harga_min' => '5000', 'harga_max' => '30000'])]);
        $engine->run($doc);
        $this->assertFalse($this->hargaFired($doc));
    }

    public function test_price_rule_distinguishes_kemahalan_kemurahan_and_shows_range(): void
    {
        [$qv, $doc, $b] = $this->kpDoc();
        $engine = app(RuleEngine::class);
        // Beras: rentang wajar 11.000–15.000

        // 10 kg / Rp 200.000 -> 20.000/kg -> KEMAHALAN
        $this->setBeras($doc, $b, '10', '200000');
        $engine->run($doc);
        $mahal = $this->hargaResult($doc, 'IV1-HARGA-MAHAL');
        $this->assertNotNull($mahal);
        $this->assertNull($this->hargaResult($doc, 'IV1-HARGA-MURAH'));
        $this->assertStringContainsString('kemahalan', $mahal->message);
        $this->assertStringContainsString('20.000', $mahal->message);          // harga satuan dihitung
        $this->assertStringContainsString('11.000', $mahal->message);          // batas bawah
        $this->assertStringContainsString('15.000', $mahal->message);          // batas atas

        // 10 kg / Rp 50.000 -> 5.000/kg -> KEMURAHAN
        $this->setBeras($doc, $b, '10', '50000');
        $engine->run($doc);
        $murah = $this->hargaResult($doc, 'IV1-HARGA-MURAH');
        $this->assertNotNull($murah);
        $this->assertNull($this->hargaResult($doc, 'IV1-HARGA-MAHAL'));
        $this->assertStringContainsString('kemurahan', $murah->message);
        $this->assertStringContainsString('5.000', $murah->message);
    }

    public function test_max_kons_rule_flags_excessive_quantity(): void
    {
        [$qv, $doc, $b] = $this->kpDoc();
        $engine = app(RuleEngine::class);

        $br = BlockRow::where('block_id', $b->id)->where('row_index', 1)->firstOrFail();
        $this->assertArrayHasKey('max_kons', $br->ref, 'seed harus memuat max_kons');

        $fired = fn () => RuleResult::where('document_id', $doc->id)->where('row_index', 1)
            ->whereIn('status', ['error', 'warning'])
            ->whereHas('rule', fn ($q) => $q->where('code', 'IV1-KUANTITAS-MAX'))->exists();

        $this->setBeras($doc, $b, '5', '60000');
        $engine->run($doc);
        $this->assertFalse($fired());

        $this->setBeras($doc, $b, '40', '480000');
        $engine->run($doc);
        $this->assertTrue($fired());
    }

    public function test_admin_edits_commodity_price_inline(): void
    {
        [$qv, , $b] = $this->kpDoc();
        $admin = User::where('username', 'admin')->firstOrFail();
        $br = BlockRow::where('block_id', $b->id)->where('code', '002')->firstOrFail();

        $this->actingAs($admin)->post('/admin/harga/simpan', [
            'row_id' => $br->id, 'code' => '002', 'label' => 'Beras (uji)',
            'harga_min' => '12000', 'harga_max' => '17000', 'kalori' => '3600',
        ])->assertRedirect()->assertSessionHas('ok');

        $br->refresh();
        $this->assertSame('Beras (uji)', $br->label);
        $this->assertSame('12000', $br->ref['harga_min']);
        $this->assertSame('17000', $br->ref['harga_max']);
        $this->assertArrayHasKey('max_kons', $br->ref, 'kunci ref lain tidak boleh hilang');

        // min > max ditolak
        $this->actingAs($admin)->post('/admin/harga/simpan', [
            'row_id' => $br->id, 'label' => 'Beras', 'harga_min' => '99999', 'harga_max' => '1',
        ])->assertSessionHasErrors('harga');
    }

    public function test_admin_bulk_price_import_matches_by_code(): void
    {
        [$qv, , $b] = $this->kpDoc();
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)->post('/admin/harga', [
            'data' => "code,harga_min,harga_max,kalori\n002,13000,16000,3611\n999,1,2,\n",
        ])->assertRedirect()->assertSessionHas('ok');

        $br = BlockRow::where('block_id', $b->id)->where('code', '002')->firstOrFail();
        $this->assertSame('13000', $br->ref['harga_min']);
        $this->assertSame('3611', $br->ref['kalori']);
    }
}
