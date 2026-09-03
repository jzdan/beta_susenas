<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\SurveyCycle;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CycleLockTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function kpDoc(): Document
    {
        return Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))
            ->where('status', '!=', 'bersih')->firstOrFail();
    }

    public function test_admin_locks_and_unlocks_a_cycle(): void
    {
        $doc = $this->kpDoc();
        $cycle = $doc->sample->cycle;
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)->get("/admin/siklus/{$cycle->id}")->assertOk()->assertSee('Penutupan siklus');

        $this->actingAs($admin)->post("/admin/siklus/{$cycle->id}/kunci")->assertRedirect();
        $this->assertTrue($cycle->fresh()->isLocked());

        $this->actingAs($admin)->post("/admin/siklus/{$cycle->id}/buka")->assertRedirect();
        $this->assertFalse($cycle->fresh()->isLocked());
    }

    public function test_locked_cycle_blocks_entry_and_cleaning_writes(): void
    {
        $doc = $this->kpDoc();
        $doc->sample->cycle->update(['status' => 'selesai']);

        $operator = User::where('username', 'ppl_ani')->firstOrFail();
        $q = \App\Models\Question::whereIn('block_id',
            \App\Models\Block::where('qversion_id', $doc->qversion_id)->pluck('id'))
            ->where('var_name', 'banyak_art')->firstOrFail();

        $this->actingAs($operator)->postJson("/entri/{$doc->id}/jawaban", [
            'question_id' => $q->id, 'value' => '5',
        ])->assertStatus(423);

        $this->actingAs($operator)->post("/entri/{$doc->id}/finalisasi")->assertStatus(423);

        $editor = User::where('username', 'siti_editor')->firstOrFail();
        $this->actingAs($editor)->post("/pengolahan/{$doc->id}/koreksi", [
            'question_id' => $q->id, 'clean_value' => '3', 'reason' => 'uji',
        ])->assertStatus(423);
        $this->actingAs($editor)->post("/pengolahan/{$doc->id}/bersih")->assertStatus(423);
    }

    public function test_readiness_flags_unresolved_samples(): void
    {
        $cycle = SurveyCycle::whereHas('samples')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();

        $res = $this->actingAs($admin)->get("/admin/siklus/{$cycle->id}")->assertOk();
        $this->assertIsInt($res->viewData('unresolved'));
        $this->assertIsBool($res->viewData('ready'));
    }

    public function test_cycle_report_shows_response_rate_funnel_and_top_rules(): void
    {
        $cycle = SurveyCycle::whereHas('samples')->firstOrFail();
        $res = $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->get("/admin/siklus/{$cycle->id}")->assertOk();

        $resp = $res->viewData('response');
        $this->assertSame($resp['eligible'], max(0, $resp['eligible']));
        $this->assertLessThanOrEqual(100.0, $resp['rate']);
        $this->assertCount(4, $res->viewData('funnel'));
        $res->assertSee('Respons &amp; disposisi', false)->assertSee('Temuan tersering');
    }
}
