<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\RuleResult;
use App\Models\User;
use App\Models\WarningAck;
use App\Services\RuleEngine;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarningAckTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function docWithWarning(): array
    {
        $doc = Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))
            ->get()
            ->first(function ($d) {
                app(RuleEngine::class)->run($d);
                return RuleResult::where('document_id', $d->id)->where('status', 'warning')->exists();
            });
        $this->assertNotNull($doc, 'butuh dokumen KP dengan minimal satu warning');

        $rr = RuleResult::with('rule')->where('document_id', $doc->id)->where('status', 'warning')->first();

        return [$doc, $rr];
    }

    public function test_editor_can_acknowledge_and_revoke_a_warning(): void
    {
        [$doc, $rr] = $this->docWithWarning();
        $doc->update(['status' => 'selesai']);
        $editor = User::where('username', 'siti_editor')->firstOrFail();

        $payload = [
            'rule_code' => $rr->rule->code,
            'target_var' => (string) $rr->target_var,
            'row_index' => (int) $rr->row_index,
            'note' => 'Sudah dikonfirmasi ke responden, nilainya wajar.',
        ];

        $this->actingAs($editor)->post("/pengolahan/{$doc->id}/terima-warning", $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('warning_acks', [
            'document_id' => $doc->id,
            'rule_code' => $rr->rule->code,
            'by_user' => $editor->id,
        ]);

        $this->actingAs($editor)->get("/pengolahan/{$doc->id}")
            ->assertOk()->assertSee('Sudah dikonfirmasi ke responden');

        $this->actingAs($editor)->post("/pengolahan/{$doc->id}/batal-terima-warning", [
            'rule_code' => $rr->rule->code,
            'target_var' => (string) $rr->target_var,
            'row_index' => (int) $rr->row_index,
        ])->assertRedirect();

        $this->assertSame(0, WarningAck::where('document_id', $doc->id)->count());
    }

    public function test_ack_needs_a_note_and_a_real_warning(): void
    {
        [$doc, $rr] = $this->docWithWarning();
        $doc->update(['status' => 'selesai']);
        $editor = User::where('username', 'siti_editor')->firstOrFail();

        $this->actingAs($editor)->post("/pengolahan/{$doc->id}/terima-warning", [
            'rule_code' => $rr->rule->code, 'target_var' => (string) $rr->target_var,
            'row_index' => (int) $rr->row_index, 'note' => 'x',
        ])->assertSessionHasErrors('note');

        $this->actingAs($editor)->post("/pengolahan/{$doc->id}/terima-warning", [
            'rule_code' => 'TIDAK-ADA', 'target_var' => 'zzz', 'row_index' => 9,
            'note' => 'catatan yang cukup panjang',
        ])->assertStatus(404);
    }

    public function test_correcting_the_answer_clears_a_stale_ack(): void
    {
        [$doc, $rr] = $this->docWithWarning();
        $doc->update(['status' => 'selesai']);
        $editor = User::where('username', 'siti_editor')->firstOrFail();

        WarningAck::create([
            'document_id' => $doc->id, 'rule_code' => $rr->rule->code,
            'target_var' => (string) $rr->target_var, 'row_index' => (int) $rr->row_index,
            'note' => 'ditinjau', 'by_user' => $editor->id,
        ]);

        $q = \App\Models\Question::where('var_name', $rr->target_var)->first();
        if (! $q) {
            $this->markTestSkipped('warning tidak terikat ke satu rincian');
        }

        $this->actingAs($editor)->post("/pengolahan/{$doc->id}/koreksi", [
            'question_id' => $q->id,
            'art_index' => (int) $rr->row_index,
            'clean_value' => '1',
            'reason' => 'koreksi uji',
        ])->assertRedirect();

        $this->assertSame(0, WarningAck::where('document_id', $doc->id)
            ->where('target_var', (string) $rr->target_var)->count());
    }
}
