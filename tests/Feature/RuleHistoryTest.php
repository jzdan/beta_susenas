<?php

namespace Tests\Feature;

use App\Models\QuestionnaireVersion;
use App\Models\RuleRevision;
use App\Models\User;
use App\Models\ValidationRule;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Inc 158 riwayat & kembalikan aturan; Inc 159 contoh kasus uji. */
class RuleHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function rule(): ValidationRule
    {
        return ValidationRule::whereHas('qversion', fn ($q) => $q->where('is_active', true))
            ->where('row_block', '')->orderBy('id')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    public function test_edit_records_original_and_change_then_restore(): void
    {
        $r = $this->rule();
        $origMsg = $r->message;
        $this->actingAs($this->admin())->post('/admin/aturan', [
            'rule_id' => $r->id, 'code' => $r->code, 'expr' => $r->expr, 'message' => 'pesan baru',
            'severity' => $r->severity, 'scope' => $r->scope, 'row_block' => '', 'target_var' => $r->target_var,
        ])->assertRedirect();

        $revs = RuleRevision::where('rule_id', $r->id)->orderBy('id')->get();
        $this->assertSame(['awal', 'ubah'], $revs->pluck('action')->all());
        $this->assertSame($origMsg, $revs[0]->data['message']);
        $this->assertSame(['message' => [$origMsg, 'pesan baru']], $revs[1]->diffFrom($revs[0]));

        $this->actingAs($this->admin())->get("/admin/aturan/{$r->id}")->assertOk()
            ->assertSee('Riwayat perubahan')->assertSee('Kembalikan');

        $this->actingAs($this->admin())->post("/admin/aturan/{$r->id}/kembalikan/{$revs[0]->id}")->assertRedirect();
        $this->assertSame($origMsg, $r->fresh()->message);
        $this->assertSame('kembalikan', RuleRevision::where('rule_id', $r->id)->orderByDesc('id')->value('action'));
    }

    public function test_toggle_is_recorded_as_matikan(): void
    {
        $r = $this->rule();
        $this->actingAs($this->admin())->post("/admin/aturan/{$r->id}/toggle")->assertRedirect();
        $this->assertSame($r->active ? 'matikan' : 'nyalakan',
            RuleRevision::where('rule_id', $r->id)->orderByDesc('id')->value('action'));
    }

    public function test_test_cases_are_saved_and_evaluated(): void
    {
        $qv = QuestionnaireVersion::where('is_active', true)->firstOrFail();
        $r = ValidationRule::create(['qversion_id' => $qv->id, 'code' => 'UJI-X', 'scope' => 'dokumen',
            'row_block' => '', 'target_var' => '', 'expr' => 'n(uji_a) > 10', 'severity' => 'warning',
            'message' => 'x', 'active' => true]);

        $this->actingAs($this->admin())->post("/admin/aturan/{$r->id}/uji", ['cases' => [
            ['name' => 'besar', 'isian' => 'uji_a=11', 'expect' => '1'],
            ['name' => 'kecil', 'isian' => 'uji_a=3', 'expect' => '0'],
            ['name' => 'salah harap', 'isian' => 'uji_a=3', 'expect' => '1'],
            ['name' => '', 'isian' => '', 'expect' => '1'],
        ]])->assertRedirect()->assertSessionHas('ok', fn ($m) => str_contains($m, '2 dari 3'));
        $this->assertCount(3, $r->fresh()->test_cases);

        // rumus diubah lewat pratinjau: contoh uji ikut dihitung atas rumus yang sedang diketik
        $j = $this->actingAs($this->admin())->postJson('/admin/aturan-pratinjau', [
            'qv' => $qv->id, 'expr' => 'n(uji_a) > 2', 'rule_id' => $r->id,
        ])->json('tests');
        $this->assertSame(['total' => 3, 'pass' => 2, 'fails' => ['kecil']], $j);

        $this->actingAs($this->admin())->get("/admin/aturan?qv={$qv->id}")->assertOk()->assertSee('uji 2/3');
    }

    public function test_parse_isian(): void
    {
        $this->assertSame(['a' => '1', 'b' => 'dua', 'c' => ''],
            \App\Services\RuleTester::parse("a=1; b = 'dua'\nc=; 9x=3; tanpa"));
    }
}
