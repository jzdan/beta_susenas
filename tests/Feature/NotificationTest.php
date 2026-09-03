<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\SampleHousehold;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_returning_a_document_notifies_the_petugas(): void
    {
        $doc = Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))->firstOrFail();
        $doc->update(['status' => 'selesai']);
        $op = User::where('username', 'ppl_ani')->firstOrFail();
        $doc->sample->update(['petugas_id' => $op->id]);
        $pml = User::where('username', 'rahmawati')->firstOrFail();

        $this->actingAs($pml)->post("/pengolahan/{$doc->id}/kembalikan", [
            'reason' => 'Blok IV.1 kuantitas beras tidak wajar — konfirmasi.',
        ])->assertRedirect();

        $n = $op->fresh()->unreadNotifications()->first();
        $this->assertNotNull($n);
        $this->assertSame('document_returned', $n->data['kind']);
        $this->assertStringContainsString('rahmawati', $n->data['by'] === 'Rahmawati' ? 'rahmawati' : $n->data['by']);
        $this->assertStringContainsString('kuantitas beras', $n->data['body']);
    }

    public function test_assigning_a_sample_notifies_the_new_petugas(): void
    {
        $pml = User::where('username', 'rahmawati')->firstOrFail();
        $op = User::where('username', 'ppl_eka')->firstOrFail();
        $sample = SampleHousehold::where('wilayah_code', 'like', '3526%')
            ->where('petugas_id', '!=', $op->id)->firstOrFail();

        $this->actingAs($pml)->post("/monitoring/sampel/{$sample->id}/tugaskan", ['petugas_id' => $op->id]);

        $this->assertSame(1, $op->fresh()->unreadNotifications()->count());
        $this->assertSame('sample_assigned', $op->fresh()->unreadNotifications()->first()->data['kind']);
    }

    public function test_opening_a_notification_marks_it_read_and_redirects(): void
    {
        $op = User::where('username', 'ppl_dodi')->firstOrFail();
        $sample = SampleHousehold::first();
        $op->notify(new \App\Notifications\SampleAssigned($sample, 'PML'));
        $id = $op->unreadNotifications()->first()->id;

        $this->actingAs($op)->get("/notifikasi/{$id}")
            ->assertRedirect(route('monitoring.sample', $sample));
        $this->assertSame(0, $op->fresh()->unreadNotifications()->count());
    }

    public function test_bell_shows_unread_count(): void
    {
        $op = User::where('username', 'ppl_ani')->firstOrFail();
        $op->notify(new \App\Notifications\SampleAssigned(SampleHousehold::first(), 'PML'));

        $this->actingAs($op)->get('/monitoring')->assertOk()->assertSee('notif-count', false);
    }
}
