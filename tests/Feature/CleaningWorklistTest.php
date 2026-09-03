<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\RuleResult;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleaningWorklistTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_index_shows_cross_document_rule_summary(): void
    {
        $pml = User::where('username', 'rahmawati')->firstOrFail();
        $res = $this->actingAs($pml)->get('/pengolahan');
        $res->assertOk();
        $res->assertSee('Rekap temuan validasi');

        $agg = collect($res->viewData('ruleAgg'));
        $this->assertGreaterThan(0, $agg->count());
        // setiap baris rekap mengacu ke jumlah dokumen berbeda yang kena
        $this->assertTrue($agg->every(fn ($r) => $r->n_doc >= 1 && $r->n_hit >= $r->n_doc));
        // error diurutkan di atas warning
        $severities = $agg->pluck('severity')->values();
        $firstWarn = $severities->search('warning');
        $lastError = $severities->reverse()->search('error');
        if ($firstWarn !== false && $lastError !== false) {
            $this->assertLessThan($severities->count() - $lastError, $firstWarn);
        }
    }

    public function test_rule_filter_limits_document_list(): void
    {
        $pml = User::where('username', 'rahmawati')->firstOrFail();
        $agg = collect($this->actingAs($pml)->get('/pengolahan')->viewData('ruleAgg'));
        $code = $agg->first()->code;

        $res = $this->actingAs($pml)->get('/pengolahan?rule=' . $code);
        $res->assertOk();
        $rows = collect($res->viewData('rows'));
        $this->assertGreaterThan(0, $rows->count());
        foreach ($rows as $r) {
            $hit = RuleResult::where('document_id', $r['doc']->id)
                ->whereIn('status', ['error', 'warning'])
                ->whereHas('rule', fn ($q) => $q->where('code', $code))->exists();
            $this->assertTrue($hit, "dok #{$r['doc']->id} seharusnya kena {$code}");
        }
    }

    public function test_queue_row_shows_note_count_and_open_warning_count(): void
    {
        $pml = User::where('username', 'rahmawati')->firstOrFail();
        $editor = User::where('username', 'siti_editor')->firstOrFail();

        $doc = Document::query()->join('sample_households as s', 's.id', '=', 'documents.sample_id')
            ->where('s.wilayah_code', 'like', '3526%')
            ->where('documents.status', 'selesai')->select('documents.*')->firstOrFail();

        \App\Models\DocumentNote::create(['document_id' => $doc->id, 'body' => 'cek ini', 'by_user' => $pml->id]);

        $rr = \App\Models\RuleResult::with('rule')->where('document_id', $doc->id)
            ->where('status', 'warning')->first();
        if ($rr) {
            \App\Models\WarningAck::create([
                'document_id' => $doc->id, 'rule_code' => $rr->rule->code,
                'target_var' => (string) $rr->target_var, 'row_index' => (int) $rr->row_index,
                'note' => 'ditinjau', 'by_user' => $editor->id,
            ]);
        }

        $rows = collect($this->actingAs($pml)->get('/pengolahan')->viewData('rows'));
        $row = $rows->firstWhere(fn ($r) => $r['doc']->id === $doc->id);

        $this->assertSame(1, $row['notes']);
        if ($rr) {
            $this->assertSame($row['warn'] - 1, $row['warn_open']);
        }
    }

    public function test_free_text_search_filters_the_queue(): void
    {
        $pml = User::where('username', 'rahmawati')->firstOrFail();
        $target = Document::query()->join('sample_households as s', 's.id', '=', 'documents.sample_id')
            ->where('s.wilayah_code', 'like', '3526%')
            ->select('documents.*', 's.krt_name', 's.no_bs')->firstOrFail();

        // cari berdasarkan nama KRT
        $rows = collect($this->actingAs($pml)->get('/pengolahan?q='.urlencode($target->krt_name))->viewData('rows'));
        $this->assertTrue($rows->isNotEmpty());
        $this->assertTrue($rows->every(fn ($r) => str_contains(
            $r['doc']->krt_name.$r['doc']->wilayah_code.$r['doc']->no_bs.$r['doc']->no_ruta.$r['doc']->id,
            $target->krt_name
        ) || $r['doc']->id === $target->id));

        // cari berdasarkan #id dokumen
        $byId = collect($this->actingAs($pml)->get('/pengolahan?q='.$target->id)->viewData('rows'));
        $this->assertTrue($byId->contains(fn ($r) => $r['doc']->id === $target->id));

        // query yang tidak cocok apa pun
        $none = collect($this->actingAs($pml)->get('/pengolahan?q=zzz-tidak-ada-xyz')->viewData('rows'));
        $this->assertCount(0, $none);
    }

    public function test_mark_clean_with_next_jumps_to_the_next_queued_document(): void
    {
        $pml = User::where('username', 'rahmawati')->firstOrFail();

        // dua dokumen selesai tanpa error dalam cakupan
        $docs = Document::query()->join('sample_households as s', 's.id', '=', 'documents.sample_id')
            ->where('s.wilayah_code', 'like', '3526%')
            ->where('documents.status', 'selesai')
            ->select('documents.*')->limit(2)->get();
        $this->assertCount(2, $docs);
        foreach ($docs as $d) {
            RuleResult::where('document_id', $d->id)->where('status', 'error')->delete();
            \App\Models\ValidationRule::where('qversion_id', $d->qversion_id)
                ->where('severity', 'error')->update(['active' => false]);
        }

        $first = $this->actingAs($pml)->get("/pengolahan/{$docs[0]->id}");
        $next = $first->viewData('nextDoc');
        $this->assertNotNull($next);

        $res = $this->actingAs($pml)->post("/pengolahan/{$docs[0]->id}/bersih", ['next' => '1']);
        $res->assertRedirect(route('pengolahan.show', $next));
        $this->assertSame('bersih', $docs[0]->fresh()->status);
    }

    public function test_bulk_clean_marks_eligible_and_skips_docs_with_errors(): void
    {
        $pml = User::where('username', 'rahmawati')->firstOrFail();

        $ok = Document::query()->join('sample_households as s', 's.id', '=', 'documents.sample_id')
            ->where('s.wilayah_code', 'like', '3526%')->where('documents.status', 'selesai')
            ->select('documents.*')->firstOrFail();

        // satu dokumen dengan error nyata: aktifkan aturan yang pasti menyala hanya untuknya
        $bad = Document::query()->join('sample_households as s', 's.id', '=', 'documents.sample_id')
            ->where('s.wilayah_code', 'like', '3526%')->where('documents.status', 'selesai')
            ->where('documents.id', '!=', $ok->id)->where('documents.qversion_id', $ok->qversion_id)
            ->select('documents.*')->firstOrFail();

        // nonaktifkan semua aturan error untuk versi ini, lalu buat 1 aturan error khusus dok $bad
        \App\Models\ValidationRule::where('qversion_id', $ok->qversion_id)
            ->where('severity', 'error')->update(['active' => false]);
        $bad->sample->update(['no_ruta' => '888']);
        \App\Models\ValidationRule::create([
            'qversion_id' => $ok->qversion_id, 'code' => 'UJI-ERR', 'scope' => 'dokumen', 'row_block' => '',
            'target_var' => '', 'expr' => 'sampel_ruta == "888"',
            'severity' => 'error', 'message' => 'uji', 'active' => true,
        ]);

        $this->actingAs($pml)->post('/pengolahan/bersih-massal', ['ids' => [$ok->id, $bad->id]])
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('bersih', $ok->fresh()->status);
        $this->assertNotSame('bersih', $bad->fresh()->status);
    }

    public function test_bulk_clean_is_forbidden_for_operator(): void
    {
        $doc = Document::where('status', 'selesai')->firstOrFail();
        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->post('/pengolahan/bersih-massal', ['ids' => [$doc->id]])
            ->assertForbidden();
    }

    public function test_revalidate_reruns_engine_and_is_pml_only(): void
    {
        $operator = User::where('username', 'ppl_ani')->firstOrFail();
        $this->actingAs($operator)->post('/pengolahan/revalidasi')->assertForbidden();

        $pml = User::where('username', 'rahmawati')->firstOrFail();
        $before = RuleResult::whereIn('document_id',
            Document::query()->join('sample_households as s', 's.id', '=', 'documents.sample_id')
                ->where('s.wilayah_code', 'like', '3526%')->pluck('documents.id'))->count();

        $this->actingAs($pml)->post('/pengolahan/revalidasi')
            ->assertRedirect()
            ->assertSessionHas('ok');

        // hasil aturan tetap konsisten (idempoten) untuk data yang tidak berubah
        $after = RuleResult::whereIn('document_id',
            Document::query()->join('sample_households as s', 's.id', '=', 'documents.sample_id')
                ->where('s.wilayah_code', 'like', '3526%')->pluck('documents.id'))->count();
        $this->assertSame($before, $after);
    }
}
