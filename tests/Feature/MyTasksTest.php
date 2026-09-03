<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentNote;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyTasksTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_operator_sees_returned_docs_and_pending_samples(): void
    {
        $op = User::where('username', 'ppl_ani')->firstOrFail();
        $doc = Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))->firstOrFail();
        $doc->sample->update(['petugas_id' => $op->id, 'status' => 'proses']);
        $doc->update(['status' => 'dikembalikan', 'return_reason' => 'Blok V perlu dicek ulang.', 'returned_at' => now()]);

        $res = $this->actingAs($op)->get('/monitoring/tugas')->assertOk();
        $res->assertSee('Tugas Saya')->assertSee('Blok V perlu dicek ulang');

        $returned = collect($res->viewData('returned'));
        $this->assertTrue($returned->contains(fn ($r) => $r['doc']->id === $doc->id));
    }

    public function test_note_from_someone_else_appears_but_own_note_does_not(): void
    {
        $op = User::where('username', 'ppl_ani')->firstOrFail();
        $pml = User::where('username', 'rahmawati')->firstOrFail();
        $doc = Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))->firstOrFail();
        $doc->sample->update(['petugas_id' => $op->id]);

        DocumentNote::create(['document_id' => $doc->id, 'body' => 'dari pemeriksa', 'by_user' => $pml->id]);

        $rows = collect($this->actingAs($op)->get('/monitoring/tugas')->viewData('noteRows'));
        $this->assertTrue($rows->contains(fn ($r) => $r['doc_id'] === $doc->id));

        // catatan terakhir dari saya sendiri -> tidak muncul
        DocumentNote::create(['document_id' => $doc->id, 'body' => 'sudah saya cek', 'by_user' => $op->id]);
        $rows2 = collect($this->actingAs($op)->get('/monitoring/tugas')->viewData('noteRows'));
        $this->assertFalse($rows2->contains(fn ($r) => $r['doc_id'] === $doc->id));
    }

    public function test_operator_only_sees_own_samples(): void
    {
        $op = User::where('username', 'ppl_ani')->firstOrFail();
        $other = User::where('username', 'ppl_eka')->firstOrFail();
        \App\Models\SampleHousehold::query()->update(['petugas_id' => $other->id]);

        $res = $this->actingAs($op)->get('/monitoring/tugas')->assertOk();
        $this->assertCount(0, collect($res->viewData('pending')));
    }
}
