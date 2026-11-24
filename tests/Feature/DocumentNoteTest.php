<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentNote;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentNoteTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function kpDoc(): Document
    {
        $doc = Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))
            ->where('status', '!=', 'bersih')->firstOrFail();
        return $this->openForPetugas($doc, User::where('username', 'ppl_ani')->firstOrFail());
    }

    public function test_pml_note_is_saved_shown_and_notifies_the_operator(): void
    {
        $doc = $this->kpDoc();
        $pml = User::where('username', 'rahmawati')->firstOrFail();
        $op = User::where('username', 'ppl_ani')->firstOrFail();

        $this->actingAs($pml)->post("/dokumen/{$doc->id}/catatan", [
            'body' => 'Blok V R502 kok kosong? tolong dicek.', 'from' => 'pengolahan',
        ])->assertRedirect();

        $this->assertDatabaseHas('document_notes', [
            'document_id' => $doc->id, 'by_user' => $pml->id,
        ]);

        $n = $op->fresh()->unreadNotifications()->first();
        $this->assertNotNull($n);
        $this->assertSame('document_note', $n->data['kind']);
        $this->assertStringContainsString('R502', $n->data['body']);

        $this->actingAs($op)->get("/entri/{$doc->id}")
            ->assertOk()->assertSee('R502 kok kosong');
    }

    public function test_note_does_not_change_document_status(): void
    {
        $doc = $this->kpDoc();
        $doc->update(['status' => 'selesai']);
        $pml = User::where('username', 'rahmawati')->firstOrFail();

        $this->actingAs($pml)->post("/dokumen/{$doc->id}/catatan", ['body' => 'catatan uji']);

        $this->assertSame('selesai', $doc->fresh()->status);
    }

    public function test_note_requires_body_and_viewer_is_forbidden(): void
    {
        $doc = $this->kpDoc();

        $this->actingAs(User::where('username', 'rahmawati')->firstOrFail())
            ->post("/dokumen/{$doc->id}/catatan", ['body' => ''])
            ->assertSessionHasErrors('body');

        $this->actingAs(User::where('username', 'viewer')->firstOrFail())
            ->post("/dokumen/{$doc->id}/catatan", ['body' => 'boleh tidak ya'])
            ->assertForbidden();

        $this->assertSame(0, DocumentNote::where('document_id', $doc->id)->count());
    }
}
