<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Models\VisitPhoto;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisitPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        config(['susenas.bukti_kunjungan' => true, 'susenas.bukti_kunjungan_wajib' => false]);
        Storage::fake('local');
    }

    private function operatorDoc(): array
    {
        $ani = User::where('username', 'ppl_ani')->firstOrFail();
        $doc = Document::whereHas('sample', fn ($q) => $q->where('petugas_id', $ani->id))
            ->where('status', '!=', 'bersih')->firstOrFail();

        return [$ani, $doc];
    }

    public function test_operator_uploads_and_pml_can_view(): void
    {
        [$ani, $doc] = $this->operatorDoc();

        $this->actingAs($ani)->post("/entri/{$doc->id}/foto-kunjungan", [
            'foto' => UploadedFile::fake()->image('rumah.jpg', 800, 600),
            'caption' => 'tampak depan',
        ])->assertRedirect()->assertSessionHas('ok');

        $ph = VisitPhoto::where('sample_id', $doc->sample_id)->firstOrFail();
        $this->assertSame('tampak depan', $ph->caption);
        Storage::assertExists($ph->path);

        // admin & pml boleh lihat
        $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->get("/kunjungan/foto/{$ph->id}")->assertOk();
        $this->actingAs(User::where('username', 'rahmawati')->firstOrFail())
            ->get("/kunjungan/foto/{$ph->id}")->assertOk();
    }

    public function test_operator_cannot_upload_to_others_sample(): void
    {
        $dodi = User::where('username', 'ppl_dodi')->firstOrFail();
        [, $doc] = $this->operatorDoc();   // sampel milik ppl_ani

        $this->actingAs($dodi)->post("/entri/{$doc->id}/foto-kunjungan", [
            'foto' => UploadedFile::fake()->image('x.jpg'),
        ])->assertForbidden();
    }

    public function test_module_disabled_returns_404(): void
    {
        config(['susenas.bukti_kunjungan' => false]);
        [$ani, $doc] = $this->operatorDoc();

        $this->actingAs($ani)->post("/entri/{$doc->id}/foto-kunjungan", [
            'foto' => UploadedFile::fake()->image('x.jpg'),
        ])->assertNotFound();
    }

    public function test_uploader_can_delete(): void
    {
        [$ani, $doc] = $this->operatorDoc();
        $this->actingAs($ani)->post("/entri/{$doc->id}/foto-kunjungan", [
            'foto' => UploadedFile::fake()->image('x.jpg'),
        ]);
        $ph = VisitPhoto::where('sample_id', $doc->sample_id)->firstOrFail();

        $this->actingAs($ani)->delete("/kunjungan/foto/{$ph->id}")->assertRedirect();
        $this->assertModelMissing($ph);
        Storage::assertMissing($ph->path);
    }

    public function test_wajib_photo_blocks_finalize(): void
    {
        config(['susenas.bukti_kunjungan_wajib' => true]);
        [$ani, $doc] = $this->operatorDoc();
        $doc->update(['status' => 'draft']);

        $this->actingAs($ani)->post("/entri/{$doc->id}/finalisasi")
            ->assertSessionHasErrors('finalisasi');
    }
}
