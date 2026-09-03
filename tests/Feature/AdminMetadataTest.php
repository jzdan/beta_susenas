<?php

namespace Tests\Feature;

use App\Models\Aggregate;
use App\Models\Block;
use App\Models\BlockRow;
use App\Models\Document;
use App\Models\Parameter;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\User;
use App\Models\ValidationRule;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function admin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    private function activeQv(): QuestionnaireVersion
    {
        return QuestionnaireVersion::orderByDesc('id')->firstOrFail();
    }

    public function test_non_admin_cannot_reach_metadata(): void
    {
        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->get('/admin/metadata')->assertForbidden();
    }

    public function test_add_block_then_question_then_option(): void
    {
        $qv = $this->activeQv();
        $this->actingAs($this->admin())->post('/admin/blok', [
            'code' => 'ZZ', 'title' => 'Blok Uji', 'repeat' => 'ruta',
        ])->assertRedirect();

        $block = Block::where('qversion_id', $qv->id)->where('code', 'ZZ')->firstOrFail();

        $this->actingAs($this->admin())->post("/admin/blok/{$block->id}/rincian", [
            'code_rincian' => 'Z1', 'var_name' => 'uji_var', 'label' => 'Rincian uji', 'qtype' => 'code',
        ])->assertRedirect();

        $q = Question::where('var_name', 'uji_var')->firstOrFail();
        $this->actingAs($this->admin())->post("/admin/rincian/{$q->id}/opsi", [
            'code' => '1', 'label' => 'Ya',
        ])->assertRedirect();

        $this->assertDatabaseHas('response_options', ['question_id' => $q->id, 'code' => '1']);
    }

    public function test_can_add_a_list_block_and_reorder_blocks(): void
    {
        $qv = $this->activeQv();
        $this->actingAs($this->admin())->post('/admin/blok', [
            'code' => 'LST', 'title' => 'Daftar Uji', 'repeat' => 'list',
        ])->assertRedirect();
        $block = Block::where('qversion_id', $qv->id)->where('code', 'LST')->firstOrFail();
        $this->assertSame('list', $block->repeat);

        // blok baru ada di urutan terakhir; naikkan sekali
        $before = Block::where('qversion_id', $qv->id)->orderBy('order')->pluck('code')->values();
        $prevCode = $before[$before->count() - 2];

        $this->actingAs($this->admin())->post("/admin/blok/{$block->id}/urutan", ['arah' => 'naik'])
            ->assertRedirect();

        $after = Block::where('qversion_id', $qv->id)->orderBy('order')->pluck('code')->values();
        $this->assertSame('LST', $after[$after->count() - 2]);
        $this->assertSame($prevCode, $after[$after->count() - 1]);
    }

    public function test_block_repeat_cannot_change_when_answers_exist(): void
    {
        $doc = Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))->firstOrFail();
        $block = Block::where('qversion_id', $doc->qversion_id)->where('code', 'III')->firstOrFail();

        $this->actingAs($this->admin())->post("/admin/blok/{$block->id}", [
            'title' => 'ART (diubah)', 'repeat' => 'ruta',
        ])->assertStatus(409);

        // ubah judul saja (repeat sama) tetap boleh
        $this->actingAs($this->admin())->post("/admin/blok/{$block->id}", [
            'title' => 'ART (judul baru)', 'repeat' => $block->repeat,
        ])->assertRedirect();
        $this->assertSame('ART (judul baru)', $block->fresh()->title);
    }

    public function test_bulk_import_and_reorder_response_options(): void
    {
        $qv = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        $b = Block::create(['qversion_id' => $qv->id, 'code' => 'OPT', 'title' => 'x', 'order' => 99, 'repeat' => 'ruta']);
        $q = Question::create(['block_id' => $b->id, 'code_rincian' => 'O1', 'var_name' => 'uji_opt',
            'label' => 'Uji', 'qtype' => 'code', 'order' => 1]);

        $this->actingAs($this->admin())->post("/admin/rincian/{$q->id}/opsi-massal", [
            'data' => "1,Satu\n2,Dua\n2,Dua lagi\n,kosong\n3,Tiga",
        ])->assertRedirect();

        $opts = $q->options()->orderBy('order')->get();
        $this->assertSame(['1', '2', '3'], $opts->pluck('code')->all());   // duplikat & baris rusak dilewati

        // naikkan opsi ke-2 -> jadi pertama
        $second = $opts[1];
        $this->actingAs($this->admin())->post("/admin/opsi/{$second->id}/urutan", ['arah' => 'naik'])
            ->assertRedirect();
        $this->assertSame('2', $q->options()->orderBy('order')->first()->code);
    }

    public function test_rules_list_filters_and_bulk_toggle(): void
    {
        $qv = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();

        // filter by severity
        $res = $this->actingAs($this->admin())->get('/admin/aturan?sev=error');
        $rules = collect($res->viewData('rules'));
        $this->assertTrue($rules->isNotEmpty());
        $this->assertTrue($rules->every(fn ($r) => $r->severity === 'error'));
        $this->assertSame($qv->rules()->count(), $res->viewData('total'));

        // bulk nonaktif
        $ids = $qv->rules()->where('active', true)->limit(3)->pluck('id');
        $this->actingAs($this->admin())->post('/admin/aturan-toggle-massal', [
            'ids' => $ids->all(), 'aksi' => 'nonaktif',
        ])->assertRedirect();
        $this->assertSame(0, ValidationRule::whereIn('id', $ids)->where('active', true)->count());

        // bulk aktif kembali
        $this->actingAs($this->admin())->post('/admin/aturan-toggle-massal', [
            'ids' => $ids->all(), 'aksi' => 'aktif',
        ])->assertRedirect();
        $this->assertSame(3, ValidationRule::whereIn('id', $ids)->where('active', true)->count());
    }

    public function test_aggregate_can_be_edited_inline(): void
    {
        $qv = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();

        $this->actingAs($this->admin())->post('/admin/agregat', [
            'name' => 'uji_agg', 'label' => 'Uji', 'block_code' => 'IV.1',
            'value_var' => 'iv1_nilai_beli', 'op' => 'sum', 'filter_op' => 'any', 'order' => 5,
        ])->assertRedirect();
        $a = Aggregate::where('qversion_id', $qv->id)->where('name', 'uji_agg')->firstOrFail();

        $this->actingAs($this->admin())->post('/admin/agregat', [
            'aggregate_id' => $a->id, 'name' => 'uji_agg', 'label' => 'Uji diubah',
            'block_code' => 'IV.1', 'value_var' => 'iv1_nilai_lain', 'op' => 'max',
            'filter_op' => 'eq', 'filter_var' => 'iv1_asal_lain', 'filter_val' => '1', 'order' => 9,
        ])->assertRedirect();

        $a->refresh();
        $this->assertSame('Uji diubah', $a->label);
        $this->assertSame('max', $a->op);
        $this->assertSame('iv1_nilai_lain', $a->value_var);
        $this->assertSame(9, $a->order);
    }

    public function test_derived_field_crud_with_syntax_check(): void
    {
        $qv = $this->activeQv();

        // sintaks buruk ditolak
        $this->actingAs($this->admin())->post('/admin/turunan', [
            'var_name' => 'uji_turunan', 'label' => 'Uji', 'expr' => 'n(a) and and (',
        ])->assertSessionHasErrors('expr');
        $this->assertDatabaseMissing('derived_fields', ['var_name' => 'uji_turunan']);

        // valid -> tersimpan
        $this->actingAs($this->admin())->post('/admin/turunan', [
            'var_name' => 'uji_turunan', 'label' => 'Uji turunan', 'order' => 5,
            'expr' => 'num(banyak_art) * 2',
        ])->assertRedirect();
        $row = \App\Models\DerivedField::where('qversion_id', $qv->id)->where('var_name', 'uji_turunan')->firstOrFail();

        // update
        $this->actingAs($this->admin())->post('/admin/turunan', [
            'derived_id' => $row->id, 'var_name' => 'uji_turunan', 'label' => 'diubah',
            'expr' => 'num(banyak_art) + 1', 'order' => 5,
        ])->assertRedirect();
        $this->assertSame('diubah', $row->fresh()->label);

        // delete
        $this->actingAs($this->admin())->post("/admin/turunan/{$row->id}/hapus")->assertRedirect();
        $this->assertDatabaseMissing('derived_fields', ['id' => $row->id]);
    }

    public function test_save_rule_rejects_broken_expression(): void
    {
        $this->actingAs($this->admin())->post('/admin/aturan', [
            'code' => 'UJI-RUSAK', 'scope' => 'dokumen',
            'expr' => 'n(a) and and (', 'severity' => 'warning', 'message' => 'x',
        ])->assertStatus(400);

        $this->assertDatabaseMissing('validation_rules', ['code' => 'UJI-RUSAK']);
    }

    public function test_save_valid_rule_and_parameter(): void
    {
        $qv = $this->activeQv();
        $this->actingAs($this->admin())->post('/admin/aturan', [
            'code' => 'UJI-OK', 'scope' => 'dokumen',
            'expr' => 'blank(banyak_art)', 'severity' => 'warning', 'message' => 'ART kosong',
        ])->assertRedirect();
        $this->assertDatabaseHas('validation_rules', ['code' => 'UJI-OK', 'qversion_id' => $qv->id]);

        $this->actingAs($this->admin())->post('/admin/parameter', [
            'name' => 'ambang_uji', 'value' => '123', 'note' => 'uji',
        ])->assertRedirect();
        $this->assertDatabaseHas('parameters', ['name' => 'ambang_uji', 'value' => '123']);
    }

    public function test_duplicate_version_copies_rows_aggregates_and_row_scoped_rules(): void
    {
        // duplicateVersion menyalin dari versi TERBARU (activeQv). Buat KP jadi terbaru.
        $kp = QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
        QuestionnaireVersion::where('id', '>', $kp->id)->delete();

        $src = $kp->fresh(['blocks']);
        $srcRows = BlockRow::whereIn('block_id', $src->blocks->pluck('id'))->count();
        $srcAggs = Aggregate::where('qversion_id', $src->id)->count();
        $srcRowRules = ValidationRule::where('qversion_id', $src->id)->where('row_block', '!=', '')->count();
        $this->assertGreaterThan(100, $srcRows);       // ~194 komoditas IV.1 + 95 IV.2
        $this->assertGreaterThan(0, $srcAggs);
        $this->assertGreaterThan(0, $srcRowRules);

        $this->actingAs($this->admin())->post('/admin/versi/duplikat', ['new_code' => 'VSEN26.KP.COPY'])
            ->assertRedirect();

        $dst = QuestionnaireVersion::where('code', 'VSEN26.KP.COPY')->firstOrFail();
        $this->assertSame($srcRows, BlockRow::whereIn('block_id', $dst->blocks->pluck('id'))->count());
        $this->assertSame($srcAggs, Aggregate::where('qversion_id', $dst->id)->count());
        $this->assertSame($srcRowRules,
            ValidationRule::where('qversion_id', $dst->id)->where('row_block', '!=', '')->count());
    }
}
