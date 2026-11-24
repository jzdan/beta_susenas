<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Document;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\SampleHousehold;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Akses petugas: periode lapangan dibuka/ditutup lewat status siklus (bukan "versi aktif"),
 * isian dikembalikan tetap bisa diperbaiki, dan petugas hanya menyentuh sampel miliknya.
 */
class PetugasAccessTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function ani(): User
    {
        return User::where('username', 'ppl_ani')->firstOrFail();
    }

    private function ownDoc(string $cycleStatus, string $docStatus = 'draft'): Document
    {
        $doc = Document::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))
            ->where('status', '!=', 'bersih')->firstOrFail();
        $doc->sample->cycle->update(['status' => $cycleStatus]);
        $doc->sample->update(['petugas_id' => $this->ani()->id]);
        $doc->update(['status' => $docStatus]);

        return $doc->fresh();
    }

    private function save(Document $doc)
    {
        $q = Question::whereIn('block_id', Block::where('qversion_id', $doc->qversion_id)->pluck('id'))
            ->where('var_name', 'banyak_art')->firstOrFail();

        return $this->actingAs($this->ani())->postJson("/entri/{$doc->id}/jawaban",
            ['question_id' => $q->id, 'value' => '4', 'art_index' => 0]);
    }

    public function test_field_open_cycle_is_editable(): void
    {
        $this->save($this->ownDoc('lapangan'))->assertOk();
    }

    public function test_closed_period_is_view_only(): void
    {
        $doc = $this->ownDoc('pengolahan');
        $this->actingAs($this->ani())->get("/entri/{$doc->id}")->assertOk()
            ->assertSee('Periode lapangan sampel ini sudah ditutup')
            ->assertViewHas('canEdit', false);
        $this->save($doc)->assertForbidden();
    }

    public function test_returned_document_stays_editable_after_field_closes(): void
    {
        $this->save($this->ownDoc('pengolahan', 'dikembalikan'))->assertOk();
    }

    public function test_activating_another_version_does_not_change_petugas_access(): void
    {
        $doc = $this->ownDoc('lapangan');
        $other = QuestionnaireVersion::where('id', '!=', $doc->qversion_id)
            ->whereHas('cycle', fn ($c) => $c->where('period', '!=', $doc->sample->cycle->period))->firstOrFail();
        QuestionnaireVersion::query()->update(['is_active' => false]);
        $other->update(['is_active' => true]);

        $this->save($doc)->assertOk();
    }

    public function test_petugas_cannot_open_or_edit_someone_elses_sample(): void
    {
        $doc = $this->ownDoc('lapangan');
        $doc->sample->update(['petugas_id' => User::where('role', 'operator')
            ->where('id', '!=', $this->ani()->id)->value('id')]);

        $this->actingAs($this->ani())->get("/entri/{$doc->id}")->assertForbidden();
        $this->actingAs($this->ani())->get("/entri/{$doc->id}/cetak")->assertForbidden();
        $this->actingAs($this->ani())->get("/monitoring/sampel/{$doc->sample_id}")->assertForbidden();
        $this->save($doc->fresh())->assertForbidden();
    }

    public function test_new_document_only_while_field_is_open(): void
    {
        $sample = SampleHousehold::doesntHave('documents')->firstOrFail();
        $sample->update(['petugas_id' => $this->ani()->id]);

        $sample->cycle->update(['status' => 'pengolahan']);
        $this->actingAs($this->ani())->post("/monitoring/sampel/{$sample->id}/dokumen")->assertForbidden();

        $sample->cycle->update(['status' => 'lapangan']);
        $this->actingAs($this->ani())->post("/monitoring/sampel/{$sample->id}/dokumen")->assertRedirect();
        $this->assertTrue($sample->documents()->exists());
    }
}
