<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function freshSelesaiDoc(): Document
    {
        // dokumen KP hanya punya warning (tanpa error) → finalisasi ulang bisa lolos
        $doc = Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))->firstOrFail();
        $doc->update(['status' => 'selesai', 'return_reason' => null, 'returned_by' => null, 'returned_at' => null]);
        return $doc;
    }

    public function test_pml_returns_document_and_operator_can_resubmit(): void
    {
        $doc = $this->freshSelesaiDoc();
        $pml = User::where('username', 'rahmawati')->firstOrFail();

        $this->actingAs($pml)->post("/pengolahan/{$doc->id}/kembalikan", [
            'reason' => 'Blok IV perlu konfirmasi ulang ke responden.',
        ])->assertRedirect(route('pengolahan.index'));

        $doc->refresh();
        $this->assertSame('dikembalikan', $doc->status);
        $this->assertSame($pml->id, $doc->returned_by);
        $this->assertNotNull($doc->returned_at);
        $this->assertSame('proses', $doc->sample->fresh()->status);

        // operator melihat alasan di layar entri
        $operator = User::where('username', 'ppl_ani')->firstOrFail();
        $this->actingAs($operator)->get("/entri/{$doc->id}")
            ->assertOk()->assertSee('dikembalikan untuk perbaikan');

        // kirim ulang: status kembali 'selesai', field pengembalian dibersihkan
        $this->actingAs($operator)->post("/entri/{$doc->id}/finalisasi")->assertRedirect();
        $doc->refresh();
        $this->assertSame('selesai', $doc->status);
        $this->assertNull($doc->return_reason);
        $this->assertNull($doc->returned_by);
    }

    public function test_return_requires_reason_and_not_allowed_on_clean_doc(): void
    {
        $doc = $this->freshSelesaiDoc();
        $pml = User::where('username', 'rahmawati')->firstOrFail();

        $this->actingAs($pml)->post("/pengolahan/{$doc->id}/kembalikan", ['reason' => 'x'])
            ->assertSessionHasErrors('reason');

        $doc->update(['status' => 'bersih']);
        $this->actingAs($pml)->post("/pengolahan/{$doc->id}/kembalikan", ['reason' => 'alasan yang cukup panjang'])
            ->assertStatus(409);
    }
}
