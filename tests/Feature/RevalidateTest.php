<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentSnapshot;
use App\Models\SurveyCycle;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevalidateTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_artisan_revalidate_rebuilds_snapshots(): void
    {
        $doc = Document::where('status', '!=', 'bersih')->firstOrFail();
        DocumentSnapshot::where('document_id', $doc->id)->delete();

        $this->artisan('susenas:revalidate')->assertExitCode(0);

        $this->assertDatabaseHas('document_snapshots', ['document_id' => $doc->id]);
    }

    public function test_artisan_all_flag_includes_clean_documents(): void
    {
        $clean = Document::where('status', 'bersih')->firstOrFail();
        DocumentSnapshot::where('document_id', $clean->id)->delete();

        $this->artisan('susenas:revalidate')->assertExitCode(0);
        $this->assertDatabaseMissing('document_snapshots', ['document_id' => $clean->id]);

        $this->artisan('susenas:revalidate', ['--all' => true])->assertExitCode(0);
        $this->assertDatabaseHas('document_snapshots', ['document_id' => $clean->id]);
    }

    public function test_admin_can_revalidate_a_cycle_from_the_report_page(): void
    {
        $cycle = SurveyCycle::whereHas('samples')->firstOrFail();
        $anyDoc = Document::whereIn('sample_id', $cycle->samples()->select('id'))->firstOrFail();
        DocumentSnapshot::where('document_id', $anyDoc->id)->delete();

        $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->post("/admin/siklus/{$cycle->id}/revalidasi")
            ->assertRedirect(route('admin.siklus', $cycle));

        $this->assertDatabaseHas('document_snapshots', ['document_id' => $anyDoc->id]);
    }
}
