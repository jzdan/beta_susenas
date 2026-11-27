<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Istilah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Teks antarmuka memakai label, bukan nilai kode DB / istilah pemrograman. */
class IstilahTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_labels_fall_back_gracefully(): void
    {
        $this->assertSame('Kesalahan', Istilah::severity('error'));
        $this->assertSame('Peringatan', Istilah::severity('warning'));
        $this->assertSame('per anggota rumah tangga', Istilah::repeat('art'));
        $this->assertSame('Pencacahan', Istilah::cycleStatus('lapangan'));
        $this->assertSame('Ubah sesuatu', Istilah::audit('ubah-sesuatu'));
    }

    public function test_admin_pages_show_labels_not_raw_codes(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $rules = $this->actingAs($admin)->get('/admin/aturan')->assertOk();
        $rules->assertSee('Peringatan')->assertDontSee('Inc 141')->assertDontSee('>warning<', false);

        $this->actingAs($admin)->get('/monitoring')->assertOk()
            ->assertSee('Angka respons')->assertDontSee('Response rate')->assertDontSee('eligible');

        $this->actingAs($admin)->get('/admin/jadwal')->assertOk()
            ->assertSee('Pengolahan: petugas hanya melihat', false);
    }
}
