<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Document;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_kor_export_reimports_with_no_unknown_columns(): void
    {
        $qv = QuestionnaireVersion::where('code', 'VSEN26.K')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();

        $csv = ltrim($this->actingAs($admin)
            ->get("/admin/impor-dokumen/ekspor/{$qv->cycle_id}")
            ->assertOk()->streamedContent(), "\xEF\xBB\xBF");

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertGreaterThan(1, count($lines));           // header + minimal 1 baris

        // muat ulang sebagai pratinjau
        $res = $this->actingAs($admin)->post('/admin/impor-dokumen', [
            'cycle_id' => $qv->cycle_id, 'data' => $csv, 'mode' => 'preview',
        ])->assertOk();

        $h = $res->viewData('hasil');
        $this->assertSame([], $h['unknown'], 'ekspor sendiri tidak boleh punya kolom tak dikenal saat diimpor ulang');
        $this->assertSame(count($lines) - 1, $h['diperbarui']);   // semua dokumen cocok (diperbarui, bukan baru)
        $this->assertSame(0, $h['dibuat']);
    }

    public function test_round_trip_preserves_a_roster_value(): void
    {
        $qv = QuestionnaireVersion::where('code', 'VSEN26.K')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();

        // set nilai unik pada satu sel roster
        $doc = Document::whereHas('sample', fn ($q) => $q->where('krt_name', 'YUDI'))
            ->where('qversion_id', $qv->id)->firstOrFail();
        $q407 = Question::whereIn('block_id', \App\Models\Block::where('qversion_id', $qv->id)->pluck('id'))
            ->where('var_name', 'r407')->firstOrFail();
        Answer::updateOrCreate(
            ['document_id' => $doc->id, 'question_id' => $q407->id, 'art_index' => 2],
            ['raw_value' => '77'],
        );

        $csv = ltrim($this->actingAs($admin)
            ->get("/admin/impor-dokumen/ekspor/{$qv->cycle_id}")->streamedContent(), "\xEF\xBB\xBF");

        // kolom r407__2 harus memuat 77 pada baris YUDI
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $header = $rows[0];
        $ci = array_search('r407__2', $header, true);
        $this->assertNotFalse($ci);
        $yudi = collect($rows)->first(fn ($r) => ($r[array_search('krt_name', $header, true)] ?? '') === 'YUDI');
        $this->assertSame('77', $yudi[$ci]);
    }
}
