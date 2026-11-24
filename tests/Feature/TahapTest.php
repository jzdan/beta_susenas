<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\SampleHousehold;
use App\Models\User;
use App\Support\Tahap;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Satu urutan tahap sampel (gabungan status sampel + isian) di semua halaman. */
class TahapTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_stage_combines_sample_and_document_status(): void
    {
        $s = new SampleHousehold(['status' => 'proses']);
        $this->assertSame('belum', Tahap::of($s));
        $this->assertSame('diisi', Tahap::of($s, new Document(['status' => 'draft'])));
        $this->assertSame('diperiksa', Tahap::of($s, new Document(['status' => 'selesai'])));
        $this->assertSame('dikembalikan', Tahap::of($s, new Document(['status' => 'dikembalikan'])));
        $this->assertSame('bersih', Tahap::of($s, new Document(['status' => 'bersih'])));

        // tanda manual pengawas mengalahkan status isian
        $s->status = 'non_respons';
        $this->assertSame('non_respons', Tahap::of($s, new Document(['status' => 'draft'])));
    }

    public function test_sample_list_filters_and_shows_one_stage_column(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $doc = Document::where('status', 'bersih')->firstOrFail();

        $res = $this->actingAs($admin)->get('/monitoring/sampel?tahap=bersih')->assertOk();
        $items = $res->viewData('items');
        $this->assertTrue($items->contains('id', $doc->sample_id));
        $this->assertSame(Document::where('status', 'bersih')
            ->whereHas('sample', fn ($q) => $q->whereNotIn('status', Tahap::MANUAL))->count(), $items->count());
        $res->assertSee('Bersih')->assertDontSee('>bersih<', false);

        $belum = $this->actingAs($admin)->get('/monitoring/sampel?tahap=belum')->viewData('items');
        $this->assertTrue($belum->every(fn ($s) => $s->documents()->doesntExist()));
    }

    public function test_pages_show_stage_labels_not_raw_status_values(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $doc = Document::where('status', 'selesai')->firstOrFail();

        $this->actingAs($admin)->get("/monitoring/sampel/{$doc->sample_id}")->assertOk()
            ->assertSee('Menunggu pemeriksaan')->assertSee('Tandai non-respons');
        $this->actingAs($admin)->get("/entri/{$doc->id}")->assertOk()
            ->assertSee('Menunggu pemeriksaan');
    }
}
