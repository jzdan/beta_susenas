<?php

namespace Tests\Feature;

use App\Jobs\RecheckDocuments;
use App\Models\BackgroundTask;
use App\Models\Document;
use App\Models\QuestionnaireVersion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Inc 161 — periksa ulang massal lewat antrean latar belakang. */
class BackgroundTaskTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    protected function tearDown(): void
    {
        BackgroundTask::$inlineMax = 50;
        parent::tearDown();
    }

    private function busyVersion(): QuestionnaireVersion
    {
        return QuestionnaireVersion::withCount('documents')->orderByDesc('documents_count')->firstOrFail();
    }

    public function test_job_runs_documents_and_records_progress(): void
    {
        $qv = $this->busyVersion();
        $ids = Document::where('qversion_id', $qv->id)->pluck('id')->all();
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $t = BackgroundTask::recheck('periksa-ulang', 'uji', $qv->id, $ids);   // sync di tes

        $this->assertSame('selesai', $t->status);
        $this->assertSame(count($ids), $t->done);
        $this->assertSame(100, $t->pct());
        $this->getJson("/admin/tugas-latar/{$t->id}")->assertOk()->assertJson(['status' => 'selesai', 'running' => false]);
    }

    public function test_large_recheck_is_queued_and_shown(): void
    {
        Queue::fake();
        $qv = $this->busyVersion();
        BackgroundTask::$inlineMax = 0;
        QuestionnaireVersion::markRulesChanged($qv->id);
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($admin)->post('/admin/aturan-periksa-ulang', ['qv' => $qv->id])
            ->assertRedirect()->assertSessionHas('ok', fn ($m) => str_contains($m, 'latar belakang'));
        Queue::assertPushed(RecheckDocuments::class);
        $this->actingAs($admin)->get("/admin/aturan?qv={$qv->id}")->assertOk()->assertSee('Menunggu giliran');
    }

    public function test_stuck_task_is_flagged(): void
    {
        $t = BackgroundTask::create(['kind' => 'periksa-ulang', 'label' => 'x', 'total' => 5]);
        $t->forceFill(['created_at' => now()->subMinutes(30)])->save();
        $this->assertTrue($t->fresh()->stuck());
        $this->artisan('susenas:health')->expectsOutputToContain('queue:work');
    }
}
