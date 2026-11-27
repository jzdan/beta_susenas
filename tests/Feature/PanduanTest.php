<?php

namespace Tests\Feature;

use App\Models\GuideSlide;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PanduanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Inc 167 — panduan bergambar (carousel) per halaman admin, isinya dikelola admin. */
class PanduanTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    private function admin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    public function test_admin_adds_updates_moves_and_deletes_slides(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin());

        $this->post('/admin/panduan/wilayah', ['title' => 'Cari wilayah', 'body' => 'Ketik nama desa.',
            'image' => UploadedFile::fake()->image('a.png', 800, 500)])->assertRedirect();
        $this->post('/admin/panduan/wilayah', ['title' => 'Tambah wilayah'])->assertRedirect();
        [$a, $b] = GuideSlide::where('page_key', 'wilayah')->orderBy('order')->get()->all();
        $this->assertNotNull($a->image_path);
        Storage::disk('local')->assertExists($a->image_path);

        // urutan: B naik ke atas
        $this->post("/admin/panduan-langkah/{$b->id}/urutan", ['dir' => 'up'])->assertRedirect();
        $this->assertSame([$b->id, $a->id], GuideSlide::where('page_key', 'wilayah')->orderBy('order')->pluck('id')->all());

        // ubah + ganti gambar + sembunyikan
        $old = $a->image_path;
        $this->post("/admin/panduan-langkah/{$a->id}", ['title' => 'Cari & ubah nama', 'body' => 'Baru',
            'image' => UploadedFile::fake()->image('b.jpg', 600, 400)])->assertRedirect();
        $a->refresh();
        $this->assertSame('Cari & ubah nama', $a->title);
        $this->assertFalse($a->active, 'kotak "Tampilkan" tidak dicentang → disembunyikan');
        Storage::disk('local')->assertMissing($old);
        $this->assertCount(1, GuideSlide::forPage('wilayah'));

        // hapus → gambar ikut terhapus
        $path = $a->image_path;
        $this->post("/admin/panduan-langkah/{$a->id}/hapus")->assertRedirect();
        $this->assertDatabaseMissing('guide_slides', ['id' => $a->id]);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'panduan', 'action' => 'hapus']);
    }

    public function test_only_admin_manages_guides(): void
    {
        $pml = User::where('username', 'rahmawati')->firstOrFail();
        $this->actingAs($pml)->get('/admin/panduan')->assertForbidden();
        $this->actingAs($pml)->post('/admin/panduan/wilayah', ['title' => 'x'])->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/panduan/tidak-ada')->assertNotFound();
    }

    public function test_page_shows_button_and_carousel_when_slides_exist(): void
    {
        $this->actingAs($this->admin());
        // belum ada langkah: admin melihat tautan "Buat panduan"
        $this->get('/admin/impor-wilayah')->assertSee('Buat panduan')->assertDontSee('data-panduan="wilayah"', false);

        GuideSlide::create(['page_key' => 'wilayah', 'order' => 1, 'title' => 'Langkah satu', 'body' => "Baris 1\nBaris 2"]);
        GuideSlide::create(['page_key' => 'wilayah', 'order' => 2, 'title' => 'Langkah tersembunyi', 'active' => false]);
        $this->get('/admin/impor-wilayah')->assertOk()
            ->assertSee('? Panduan')->assertSee('data-panduan="wilayah"', false)
            ->assertSee('Langkah satu')->assertSee('Baris 1<br />', false)
            ->assertDontSee('Langkah tersembunyi');
    }

    public function test_non_admin_sees_guide_but_no_create_link(): void
    {
        $pml = User::where('username', 'rahmawati')->firstOrFail();
        $this->actingAs($pml)->get('/tabulasi')->assertOk()->assertDontSee('Buat panduan');
        GuideSlide::create(['page_key' => 'tabulasi', 'order' => 1, 'title' => 'Ekspor data']);
        $this->actingAs($pml)->get('/tabulasi')->assertSee('data-panduan="tabulasi"', false)->assertDontSee('Ubah panduan ini');
    }

    public function test_image_is_served_to_logged_in_users_only(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('panduan/x.png', UploadedFile::fake()->image('x.png')->getContent());
        $s = GuideSlide::create(['page_key' => 'tabulasi', 'order' => 1, 'title' => 'x', 'image_path' => 'panduan/x.png']);
        $none = GuideSlide::create(['page_key' => 'tabulasi', 'order' => 2, 'title' => 'y']);

        $this->get("/panduan/gambar/{$s->id}")->assertRedirect('/login');
        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())->get("/panduan/gambar/{$s->id}")->assertOk();
        $this->get("/panduan/gambar/{$none->id}")->assertNotFound();
    }

    public function test_version_changes_when_content_changes(): void
    {
        $s = GuideSlide::create(['page_key' => 'jadwal', 'order' => 1, 'title' => 'a']);
        $v1 = GuideSlide::versionOf(GuideSlide::forPage('jadwal'));
        $this->travel(5)->seconds();
        $s->update(['title' => 'b']);
        $this->assertNotSame($v1, GuideSlide::versionOf(GuideSlide::forPage('jadwal')));
    }

    public function test_seeder_fills_every_page_once_and_keeps_admin_edits(): void
    {
        Storage::fake('local');
        $json = json_decode(file_get_contents(database_path('data/panduan/panduan.json')), true);
        $this->seed(PanduanSeeder::class);
        foreach (\App\Support\Panduan::pages() as $key => $p) {
            $this->assertSame(count($json[$key] ?? []), GuideSlide::where('page_key', $key)->count(), $key);
        }
        $first = GuideSlide::where('page_key', 'jadwal')->orderBy('order')->first();
        if ($first->image_path) {
            Storage::disk('local')->assertExists($first->image_path);
        }
        $first->update(['title' => 'Diubah admin']);
        $n = GuideSlide::count();
        $this->seed(PanduanSeeder::class);
        $this->assertSame($n, GuideSlide::count());
        $this->assertSame('Diubah admin', $first->fresh()->title);
    }
    public function test_auto_open_only_once_per_account_and_again_after_change(): void
    {
        $admin = $this->admin();
        $s = GuideSlide::create(['page_key' => 'wilayah', 'order' => 1, 'title' => 'Langkah satu']);
        $this->actingAs($admin)->get('/admin/impor-wilayah')->assertSee('data-auto="1"', false);

        $ver = GuideSlide::versionOf(GuideSlide::forPage('wilayah'));
        $this->postJson('/panduan/wilayah/dilihat', ['version' => $ver])->assertNoContent();
        $this->get('/admin/impor-wilayah')->assertSee('data-auto="0"', false);

        // isi diubah → muncul sendiri sekali lagi
        $this->travel(5)->seconds();
        $s->update(['title' => 'Langkah satu (baru)']);
        $this->get('/admin/impor-wilayah')->assertSee('data-auto="1"', false);
        $this->postJson('/panduan/tidak-ada/dilihat', ['version' => 'x'])->assertNotFound();
    }
    public function test_workflow_page_always_opens_and_is_admin_only(): void
    {
        GuideSlide::create(['page_key' => 'alur-kerja', 'order' => 1, 'section' => 'A. Sekali di awal',
            'title' => 'Muat Daftar Wilayah', 'link' => '/admin/impor-wilayah']);
        $admin = $this->admin();
        $ver = GuideSlide::versionOf(GuideSlide::forPage('alur-kerja'));
        GuideSlide::markSeen($admin->id, 'alur-kerja', $ver);   // sudah pernah dilihat pun tetap terbuka
        $this->actingAs($admin)->get('/alur-kerja')->assertOk()
            ->assertSee('data-auto="1"', false)->assertSee('A. Sekali di awal')
            ->assertSee('Buka halaman ini')->assertSee('href="/admin/impor-wilayah"', false);
        $this->actingAs(User::where('username', 'rahmawati')->firstOrFail())->get('/alur-kerja')->assertForbidden();
        // tautan hanya boleh alamat di dalam aplikasi
        $s = GuideSlide::where('page_key', 'alur-kerja')->first();
        $this->actingAs($admin)->post("/admin/panduan-langkah/{$s->id}", ['title' => 'x', 'link' => 'https://contoh.com'])
            ->assertSessionHasErrors('link');
    }
}
