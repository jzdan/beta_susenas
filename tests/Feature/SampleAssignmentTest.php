<?php

namespace Tests\Feature;

use App\Models\SampleHousehold;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SampleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_pml_assigns_petugas_to_a_sample_in_scope(): void
    {
        $pml = User::where('username', 'rahmawati')->firstOrFail();      // scope 3526
        $op = User::where('username', 'ppl_dodi')->firstOrFail();
        $sample = SampleHousehold::where('wilayah_code', 'like', '3526%')->firstOrFail();

        $this->actingAs($pml)->post("/monitoring/sampel/{$sample->id}/tugaskan", [
            'petugas_id' => $op->id, 'pml_id' => $pml->id,
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertSame($op->id, $sample->fresh()->petugas_id);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'sample', 'action' => 'tugaskan']);
    }

    public function test_pml_cannot_assign_sample_outside_wilayah_scope(): void
    {
        $pml = User::where('username', 'rahmawati')->firstOrFail();
        // buat sampel di luar 3526
        $outside = SampleHousehold::first();
        $outside->update(['wilayah_code' => '3502010001']);

        $this->actingAs($pml)->post("/monitoring/sampel/{$outside->id}/tugaskan", [
            'petugas_id' => User::where('username', 'ppl_ani')->value('id'),
        ])->assertForbidden();
    }

    public function test_bulk_assign_updates_selected_samples(): void
    {
        $pml = User::where('username', 'rahmawati')->firstOrFail();
        $op = User::where('username', 'ppl_eka')->firstOrFail();
        $ids = SampleHousehold::where('wilayah_code', 'like', '3526%')->take(3)->pluck('id')->all();

        $this->actingAs($pml)->post('/monitoring/sampel-tugaskan', [
            'ids' => $ids, 'petugas_id' => $op->id,
        ])->assertRedirect()->assertSessionHas('ok');

        foreach ($ids as $id) {
            $this->assertSame($op->id, SampleHousehold::find($id)->petugas_id);
        }
    }

    public function test_operator_cannot_assign(): void
    {
        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->post('/monitoring/sampel/'.SampleHousehold::first()->id.'/tugaskan', ['petugas_id' => 1])
            ->assertForbidden();
    }
}
