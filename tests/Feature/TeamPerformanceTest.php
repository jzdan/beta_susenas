<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_page_is_pml_only(): void
    {
        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->get('/monitoring/kinerja')->assertForbidden();

        $this->actingAs(User::where('username', 'rahmawati')->firstOrFail())
            ->get('/monitoring/kinerja')->assertOk()->assertSee('Kinerja Tim');
    }

    public function test_report_credits_the_examiner_who_cleaned_a_document(): void
    {
        $editor = User::where('username', 'siti_editor')->firstOrFail();
        $doc = Document::query()->join('sample_households as s', 's.id', '=', 'documents.sample_id')
            ->where('s.wilayah_code', 'like', '3526%')
            ->where('documents.status', 'selesai')->select('documents.*')->firstOrFail();

        $doc->update([
            'status' => 'bersih', 'cleaned_by' => $editor->id,
            'cleaned_at' => now(), 'submitted_at' => now()->subDays(3),
        ]);

        $rows = collect($this->actingAs(User::where('username', 'rahmawati')->firstOrFail())
            ->get('/monitoring/kinerja')->viewData('olahRows'));

        $mine = $rows->firstWhere('name', $editor->name);
        $this->assertNotNull($mine);
        $this->assertGreaterThanOrEqual(1, $mine['bersih']);
        $this->assertSame(3, (int) $mine['avg_lead_days']);
    }

    public function test_entry_row_counts_finalized_docs_per_petugas(): void
    {
        $pml = User::where('username', 'rahmawati')->firstOrFail();
        $rows = collect($this->actingAs($pml)->get('/monitoring/kinerja')->viewData('entriRows'));

        $this->assertGreaterThan(0, $rows->count());
        $this->assertTrue($rows->every(fn ($r) => $r['submit'] <= $r['total']));
    }
}
