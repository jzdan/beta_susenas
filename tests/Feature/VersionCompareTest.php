<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Parameter;
use App\Models\QuestionnaireVersion;
use App\Models\User;
use App\Models\ValidationRule;
use App\Services\QuestionnairePackage;
use App\Services\VersionDiff;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Inc 160 — bandingkan versi kuesioner. */
class VersionCompareTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_copy_is_identical_then_changes_are_reported(): void
    {
        $src = QuestionnaireVersion::where('is_active', true)->firstOrFail();
        $pkg = app(QuestionnairePackage::class);
        $copy = $pkg->import($pkg->export($src), $src->cycle_id, 'SALIN-UJI');

        $diff = app(VersionDiff::class)->compare($src, $copy);
        $this->assertSame(['added' => 0, 'removed' => 0, 'changed' => 0], VersionDiff::totals($diff));

        $rule = ValidationRule::where('qversion_id', $copy->id)->orderBy('id')->firstOrFail();
        $rule->update(['expr' => $rule->expr.' and 1 == 1']);
        Parameter::create(['qversion_id' => $copy->id, 'name' => 'param_baru_uji', 'value' => '7']);
        $blk = Block::where('qversion_id', $copy->id)->orderBy('order')->firstOrFail();
        $blk->update(['title' => 'Judul diganti']);

        $diff = app(VersionDiff::class)->compare($src, $copy);
        $this->assertSame('param_baru_uji', $diff['parameters']['added'][0]['name']);
        $this->assertSame($rule->code, explode(' — ', $diff['rules']['changed'][0]['name'])[0]);
        $this->assertArrayHasKey('expr', $diff['rules']['changed'][0]['fields']);
        $this->assertSame('Judul diganti', $diff['blocks']['changed'][0]['fields']['title'][1]);

        $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->get("/admin/versi/{$copy->id}/bandingkan?dengan={$src->id}")
            ->assertOk()->assertSee('param_baru_uji')->assertSee('Judul diganti')->assertSee('diubah');
    }
}
