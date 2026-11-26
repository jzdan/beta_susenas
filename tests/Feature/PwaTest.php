<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Inc 162 — aplikasi terpasang (Android & iPhone) dan persiapan tanpa sinyal. */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_static_pwa_files_are_valid(): void
    {
        $m = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame('standalone', $m['display']);
        $sizes = collect($m['icons'])->pluck('sizes')->all();
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
        $this->assertContains('maskable', collect($m['icons'])->pluck('purpose')->all());
        foreach ($m['icons'] as $i) {
            $this->assertFileExists(public_path(ltrim($i['src'], '/')));
        }
        $this->assertFileExists(public_path('icons/apple-touch-icon.png'));
        $this->assertSame([180, 180], array_slice(getimagesize(public_path('icons/apple-touch-icon.png')), 0, 2));
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('offline.html'));
    }

    public function test_layout_has_android_and_iphone_tags(): void
    {
        $this->actingAs(User::where('username', 'ppl_ani')->firstOrFail())
            ->get('/monitoring/tugas')->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('apple-touch-icon', false)
            ->assertSee('apple-mobile-web-app-capable', false)
            ->assertSee('name="app-user"', false)
            ->assertSee('Simpan untuk dibuka tanpa sinyal');
    }

    public function test_offline_prep_is_only_for_petugas(): void
    {
        $this->actingAs(User::where('username', 'rahmawati')->firstOrFail())
            ->get('/monitoring/tugas')->assertOk()->assertDontSee('Simpan untuk dibuka tanpa sinyal');
    }
}
