<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wilayah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WilayahBrowseTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function admin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    public function test_search_by_code_prefix_and_name(): void
    {
        $byPrefix = collect($this->actingAs($this->admin())->get('/admin/impor-wilayah?q=3526')->viewData('list'));
        $this->assertTrue($byPrefix->isNotEmpty());
        $this->assertTrue($byPrefix->every(fn ($w) => str_starts_with($w->code, '3526')));

        $byName = collect($this->actingAs($this->admin())->get('/admin/impor-wilayah?q=Bangkalan')->viewData('list'));
        $this->assertTrue($byName->contains(fn ($w) => str_contains($w->name, 'Bangkalan')));
    }

    public function test_rename_updates_the_name(): void
    {
        $w = Wilayah::where('level', 'desa')->firstOrFail();

        $this->actingAs($this->admin())->post("/admin/wilayah/{$w->id}/nama", ['name' => 'Nama Baru Desa'])
            ->assertRedirect();

        $this->assertSame('Nama Baru Desa', $w->fresh()->name);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'wilayah', 'action' => 'ubah-nama']);
    }

    public function test_import_page_still_renders_after_a_preview(): void
    {
        $csv = "code,name,level,parent_code\n352699,Kec Baru,kecamatan,3526\n";
        $this->actingAs($this->admin())->post('/admin/impor-wilayah', ['data' => $csv, 'mode' => 'preview'])
            ->assertOk()
            ->assertSee('Master Wilkerstat');
    }
}
