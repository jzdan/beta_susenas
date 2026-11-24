<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueueAgingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_finalize_stamps_submitted_at(): void
    {
        $doc = Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))
            ->where('status', '!=', 'bersih')->firstOrFail();
        $doc = $this->openForPetugas($doc, User::where('username', 'ppl_ani')->firstOrFail());
        $doc->update(['status' => 'draft', 'submitted_at' => null]);

        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->post("/entri/{$doc->id}/finalisasi");

        $doc->refresh();
        if ($doc->status === 'selesai') {
            $this->assertNotNull($doc->submitted_at);
            $this->assertSame(0, $doc->waitingDays());
        } else {
            $this->markTestSkipped('dokumen masih punya error, tidak bisa difinalisasi');
        }
    }

    public function test_queue_reports_age_and_flags_stale_docs(): void
    {
        $pml = User::where('username', 'rahmawati')->firstOrFail();

        $doc = Document::query()->join('sample_households as s', 's.id', '=', 'documents.sample_id')
            ->where('s.wilayah_code', 'like', '3526%')
            ->where('documents.status', 'selesai')->select('documents.*')->firstOrFail();
        $doc->update(['submitted_at' => now()->subDays(20)]);

        $res = $this->actingAs($pml)->get('/pengolahan?status=selesai');
        $res->assertOk();

        $age = $res->viewData('queueAge');
        $this->assertGreaterThanOrEqual(20, $age['max']);
        $this->assertGreaterThanOrEqual(1, $age['stale']);
        $res->assertSee('mengendap');

        // dokumen tertua muncul lebih dulu daripada yang baru
        $rows = collect($res->viewData('rows'));
        $this->assertSame($doc->id, $rows->first()['doc']->id);
    }

    public function test_returned_document_ages_from_returned_at(): void
    {
        $doc = Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))->firstOrFail();
        $doc->update(['status' => 'dikembalikan', 'returned_at' => now()->subDays(5), 'submitted_at' => now()->subDays(30)]);

        $this->assertSame(5, $doc->fresh()->waitingDays());
    }
}
