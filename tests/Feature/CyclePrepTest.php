<?php

namespace Tests\Feature;

use App\Models\QuestionnaireVersion;
use App\Models\SampleHousehold;
use App\Models\SurveyCycle;
use App\Models\User;
use App\Services\CyclePrep;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Persiapan periode: daftar periksa sebelum siklus dibuka untuk lapangan. */
class CyclePrepTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function admin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    /** Siklus seed yang punya versi & sampel, dikembalikan ke tahap persiapan. */
    private function preparedCycle(): SurveyCycle
    {
        $cycle = SurveyCycle::whereHas('samples')->whereHas('versions')->firstOrFail();
        $cycle->update(['status' => 'persiapan']);
        $petugas = User::where('username', 'ppl_ani')->value('id');
        $cycle->samples()->whereNull('petugas_id')->update(['petugas_id' => $petugas]);

        return $cycle->fresh();
    }

    public function test_ready_cycle_can_be_opened_for_field_work(): void
    {
        $cycle = $this->preparedCycle();
        $res = app(CyclePrep::class)->check($cycle);
        $this->assertTrue($res['siap'], implode(' | ', CyclePrep::blockers($res)));

        $this->actingAs($this->admin())->get("/admin/siklus/{$cycle->id}/persiapan")->assertOk()
            ->assertSee('Daftar periksa')->assertSee('Buka periode lapangan');
        $this->actingAs($this->admin())->post("/admin/siklus/{$cycle->id}/buka-lapangan")->assertRedirect();
        $this->assertSame('lapangan', $cycle->fresh()->status);
    }

    public function test_unassigned_sample_blocks_opening(): void
    {
        $cycle = $this->preparedCycle();
        $cycle->samples()->first()->update(['petugas_id' => null]);

        $res = app(CyclePrep::class)->check($cycle);
        $this->assertFalse($res['siap']);
        $this->assertStringContainsString('Pencacah Lapangan', implode(' ', CyclePrep::blockers($res)));

        $this->actingAs($this->admin())->post("/admin/siklus/{$cycle->id}/buka-lapangan")
            ->assertSessionHasErrors('buka');
        $this->assertSame('persiapan', $cycle->fresh()->status);
    }

    public function test_cycle_without_version_or_samples_is_not_ready(): void
    {
        $cycle = SurveyCycle::create(['name' => 'Kosong', 'period' => '2028-03', 'status' => 'persiapan']);
        $keys = collect(app(CyclePrep::class)->check($cycle)['items'])
            ->filter(fn ($i) => $i['wajib'] && ! $i['ok'])->pluck('key')->all();
        $this->assertEqualsCanonicalizing(['versi', 'sampel'], $keys);
    }

    public function test_new_cycle_cannot_start_in_field_status(): void
    {
        $this->actingAs($this->admin())->post('/admin/jadwal', [
            'name' => 'Langsung lapangan', 'period' => '2028-09', 'status' => 'lapangan',
        ])->assertSessionHasErrors('status');
        $this->assertDatabaseMissing('survey_cycles', ['name' => 'Langsung lapangan']);
    }

    public function test_activating_a_version_is_no_longer_blocked(): void
    {
        $qv = QuestionnaireVersion::where('is_active', false)->firstOrFail();
        $this->actingAs($this->admin())->post("/admin/versi/{$qv->id}/aktifkan")->assertRedirect();
        $this->assertTrue($qv->fresh()->is_active);
        $this->actingAs($this->admin())->get("/admin/metadata?qv={$qv->id}")->assertOk()
            ->assertSee('Jadikan bawaan', false)->assertDontSee('Jadikan aktif');
    }
}
