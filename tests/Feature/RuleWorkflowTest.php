<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\QuestionnaireVersion;
use App\Models\User;
use App\Models\ValidationRule;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inc 153 — alur kerja Aturan Validasi: formulir di halaman sendiri, pemeriksaan langsung,
 * jumlah sampel yang kena per aturan, dan periksa ulang setelah aturan berubah.
 */
class RuleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function admin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    private function kp(): QuestionnaireVersion
    {
        return QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
    }

    public function test_list_is_slim_and_shows_hit_counts(): void
    {
        $qv = $this->kp();
        $res = $this->actingAs($this->admin())->get("/admin/aturan?qv={$qv->id}")->assertOk();
        $res->assertSee('Buat aturan baru')->assertDontSee('Mode lanjutan')->assertDontSee('Kamus variabel');

        $hits = $res->viewData('hits');
        $this->assertGreaterThan(0, $hits->sum());
        $rule = ValidationRule::find($hits->keys()->first());
        $res->assertSee(route('pengolahan.index', ['rule' => $rule->code]), false);
    }

    public function test_edit_and_new_pages_have_their_own_form(): void
    {
        $rule = ValidationRule::where('qversion_id', $this->kp()->id)->firstOrFail();
        $this->actingAs($this->admin())->get("/admin/aturan/{$rule->id}")->assertOk()
            ->assertSee('Apa yang diperiksa')->assertSee('Hapus aturan')->assertSee($rule->code);

        $this->actingAs($this->admin())->get("/admin/aturan-baru?qv={$this->kp()->id}")->assertOk()
            ->assertSee('Jenis pemeriksaan')->assertDontSee('Hapus aturan');
        $this->actingAs($this->admin())->get("/admin/aturan-baru?qv={$this->kp()->id}&mode=rumus")->assertOk()
            ->assertSee('Apa yang diperiksa');
    }

    public function test_live_preview_reports_syntax_impact_and_example_message(): void
    {
        $qv = $this->kp();
        $bad = $this->actingAs($this->admin())->postJson('/admin/aturan-pratinjau', ['qv' => $qv->id, 'expr' => 'n(a) and and ('])
            ->assertOk()->json();
        $this->assertFalse($bad['ok']);

        $d = $this->actingAs($this->admin())->postJson('/admin/aturan-pratinjau', [
            'qv' => $qv->id, 'expr' => 'filled(banyak_art)', 'message' => 'Banyaknya anggota {banyak_art} orang.',
        ])->assertOk()->json();
        $this->assertTrue($d['ok']);
        $this->assertGreaterThan(0, $d['fire_docs']);
        $this->assertMatchesRegularExpression('/^Banyaknya anggota \d+ orang\.$/', $d['example']['text']);
    }

    public function test_rule_change_marks_samples_stale_until_rechecked(): void
    {
        $qv = $this->kp();
        // mulai dari keadaan bersih: semua isian sudah diperiksa dengan aturan sekarang
        $this->actingAs($this->admin())->post('/admin/aturan-periksa-ulang', ['qv' => $qv->id]);
        $this->assertSame(0, $qv->fresh()->staleDocuments()->count());

        $this->travel(2)->seconds();
        $rule = ValidationRule::where('qversion_id', $qv->id)->where('active', true)->firstOrFail();
        $this->actingAs($this->admin())->post("/admin/aturan/{$rule->id}/toggle");

        $open = Document::where('qversion_id', $qv->id)
            ->whereHas('sample.cycle', fn ($c) => $c->where('status', '!=', 'selesai'))->count();
        $this->assertSame($open, $qv->fresh()->staleDocuments()->count());
        $this->actingAs($this->admin())->get("/admin/aturan?qv={$qv->id}")->assertSee('belum diperiksa ulang');

        $this->travel(2)->seconds();
        $this->actingAs($this->admin())->post('/admin/aturan-periksa-ulang', ['qv' => $qv->id])
            ->assertRedirect()->assertSessionHas('ok');
        $this->assertSame(0, $qv->fresh()->staleDocuments()->count());
        $this->actingAs($this->admin())->get("/admin/aturan?qv={$qv->id}")->assertDontSee('belum diperiksa ulang');
    }

    public function test_save_returns_to_list_and_duplicate_code_is_rejected(): void
    {
        $qv = $this->kp();
        $code = ValidationRule::where('qversion_id', $qv->id)->value('code');
        $this->actingAs($this->admin())->post('/admin/aturan', [
            'qv' => $qv->id, 'code' => $code, 'expr' => 'blank(banyak_art)', 'message' => 'x',
        ])->assertSessionHasErrors('code');

        $this->actingAs($this->admin())->post('/admin/aturan', [
            'qv' => $qv->id, 'code' => 'UJI-153', 'expr' => 'blank(banyak_art)', 'message' => 'x',
        ])->assertRedirect(route('admin.aturan', ['qv' => $qv->id]));
        $this->assertDatabaseHas('validation_rules', ['code' => 'UJI-153', 'qversion_id' => $qv->id]);
    }
}
