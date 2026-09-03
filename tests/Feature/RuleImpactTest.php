<?php

namespace Tests\Feature;

use App\Models\QuestionnaireVersion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuleImpactTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function admin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    public function test_impact_preview_counts_firing_documents_without_writing(): void
    {
        $ruleResultsBefore = \App\Models\RuleResult::count();

        // ekspresi yang pasti menyala di semua dokumen: 1 == 1
        $res = $this->actingAs($this->admin())->postJson('/admin/aturan/dampak', [
            'expr' => '1 == 1',
        ]);
        $res->assertOk()->assertJson(['ok' => true]);
        $d = $res->json();

        $this->assertGreaterThan(0, $d['docs']);
        $this->assertSame($d['docs'], $d['fire_docs']);
        $this->assertNotEmpty($d['samples']);
        $this->assertSame($ruleResultsBefore, \App\Models\RuleResult::count(), 'pratinjau tidak boleh menulis');
    }

    public function test_impact_preview_reports_zero_for_never_true_expression(): void
    {
        $d = $this->actingAs($this->admin())->postJson('/admin/aturan/dampak', [
            'expr' => '1 == 0',
        ])->json();

        $this->assertSame(0, $d['fire_docs']);
        $this->assertSame([], $d['samples']);
    }

    public function test_impact_preview_rejects_bad_syntax_and_is_admin_only(): void
    {
        $this->actingAs($this->admin())->postJson('/admin/aturan/dampak', ['expr' => 'and and ('])
            ->assertOk()->assertJson(['ok' => false]);

        $this->actingAs(User::where('username', 'siti_editor')->firstOrFail())
            ->postJson('/admin/aturan/dampak', ['expr' => '1 == 1'])
            ->assertForbidden();
    }
}
