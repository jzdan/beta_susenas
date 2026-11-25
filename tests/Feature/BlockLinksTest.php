<?php

namespace Tests\Feature;

use App\Models\Aggregate;
use App\Models\DerivedField;
use App\Models\QuestionnaireVersion;
use App\Models\User;
use App\Models\ValidationRule;
use App\Services\BlockLinks;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Inc 155 — keterhubungan kuesioner ↔ aturan, agregat (neraca), turunan. */
class BlockLinksTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function kp(): QuestionnaireVersion
    {
        return QuestionnaireVersion::where('code', 'VSEN26.KP')->firstOrFail();
    }

    public function test_links_follow_variables_aggregates_and_derived_chains(): void
    {
        $qv = $this->kp();
        $links = app(BlockLinks::class)->for($qv);

        // aturan per-baris → blok barisnya
        $rowRule = ValidationRule::where('qversion_id', $qv->id)->where('row_block', '!=', '')->firstOrFail();
        $this->assertContains($rowRule->row_block, $links['rules'][$rowRule->id]);

        // agregat → blok sumbernya
        $agg = Aggregate::where('qversion_id', $qv->id)->firstOrFail();
        $this->assertSame([$agg->block_code], $links['aggregates'][$agg->id]);

        // turunan yang memakai agregat itu mewarisi bloknya
        $d = DerivedField::where('qversion_id', $qv->id)->get()
            ->first(fn ($d) => str_contains($d->expr, $agg->name));
        if ($d) {
            $this->assertContains($agg->block_code, $links['derived'][$d->id]);
            $this->assertContains('turunan '.$d->var_name, $links['usedBy'][$agg->name]);
        }

        // rangkuman per blok konsisten dengan daftar per aturan
        $code = $rowRule->row_block;
        $n = collect($links['rules'])->filter(fn ($codes) => in_array($code, $codes, true))->count();
        $this->assertSame($n, $links['byBlock'][$code]['rules']);
    }

    public function test_pages_show_and_filter_by_block(): void
    {
        $qv = $this->kp();
        $admin = User::where('username', 'admin')->firstOrFail();
        $links = app(BlockLinks::class)->for($qv);
        $code = collect($links['byBlock'])->filter(fn ($c) => $c['rules'] > 0)->keys()->first();

        $res = $this->actingAs($admin)->get("/admin/aturan?qv={$qv->id}&blok={$code}")->assertOk()
            ->assertSee('Hanya menampilkan')->assertSee('bchip', false);
        $this->assertSame($links['byBlock'][$code]['rules'], $res->viewData('rules')->count());

        $this->actingAs($admin)->get("/admin/aturan?qv={$qv->id}&tab=neraca")->assertOk()
            ->assertSee('dipakai oleh')->assertSee('bchip', false);
        $this->actingAs($admin)->get("/admin/metadata?qv={$qv->id}")->assertOk()
            ->assertSee('Terhubung:')->assertSee("blok={$code}", false);
    }
}
