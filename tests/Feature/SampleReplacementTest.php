<?php

namespace Tests\Feature;

use App\Models\SampleHousehold;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SampleReplacementTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function pml(): User
    {
        return User::where('username', 'rahmawati')->firstOrFail();
    }

    private function sample(): SampleHousehold
    {
        return SampleHousehold::where('wilayah_code', 'like', '3526%')
            ->where('status', '!=', 'ganti_sampel')->whereNull('replaces_id')->firstOrFail();
    }

    public function test_non_response_status_requires_a_reason(): void
    {
        $s = $this->sample();

        $this->actingAs($this->pml())->post("/monitoring/sampel/{$s->id}/status", [
            'new_status' => 'non_respons',
        ])->assertSessionHasErrors('reason');

        $this->actingAs($this->pml())->post("/monitoring/sampel/{$s->id}/status", [
            'new_status' => 'non_respons', 'reason' => 'menolak', 'note' => 'Responden menolak dua kali.',
        ])->assertRedirect();

        $s->refresh();
        $this->assertSame('non_respons', $s->status);
        $this->assertSame('menolak', $s->nonresponse_reason);
        $this->assertSame('Responden menolak dua kali.', $s->status_note);
    }

    public function test_changing_back_to_proses_clears_reason(): void
    {
        $s = $this->sample();
        $s->update(['status' => 'non_respons', 'nonresponse_reason' => 'akses', 'status_note' => 'banjir']);

        $this->actingAs($this->pml())->post("/monitoring/sampel/{$s->id}/status", ['new_status' => 'proses']);

        $s->refresh();
        $this->assertNull($s->nonresponse_reason);
        $this->assertNull($s->status_note);
    }

    public function test_creating_a_replacement_links_both_samples(): void
    {
        $s = $this->sample();

        $res = $this->actingAs($this->pml())->post("/monitoring/sampel/{$s->id}/ganti", [
            'reason' => 'pindah', 'note' => 'Sekeluarga pindah ke kota lain.',
        ]);
        $res->assertRedirect();

        $s->refresh();
        $this->assertSame('ganti_sampel', $s->status);
        $this->assertSame('pindah', $s->nonresponse_reason);

        $pengganti = SampleHousehold::where('replaces_id', $s->id)->firstOrFail();
        $this->assertSame($s->wilayah_code, $pengganti->wilayah_code);
        $this->assertSame($s->no_bs, $pengganti->no_bs);
        $this->assertSame('belum', $pengganti->status);
        $this->assertSame($s->petugas_id, $pengganti->petugas_id);
        $this->assertStringEndsWith('P', $pengganti->no_ruta);

        // tidak bisa membuat pengganti kedua
        $this->actingAs($this->pml())->post("/monitoring/sampel/{$s->id}/ganti", ['reason' => 'pindah'])
            ->assertStatus(409);
    }

    public function test_operator_cannot_replace(): void
    {
        $s = $this->sample();
        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->post("/monitoring/sampel/{$s->id}/ganti", ['reason' => 'menolak'])
            ->assertForbidden();
    }
}
