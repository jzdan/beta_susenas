<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wilayah;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PetugasBangkalanSeeder;
use Database\Seeders\WilayahBangkalanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Inc 165 — master wilayah 18 kecamatan Bangkalan + tim lapangan contoh (tidak dimuat saat tes biasa). */
class WilayahPetugasBangkalanTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_wilayah_has_18_kecamatan_and_281_desa_with_bps_code_lengths(): void
    {
        $this->seed(WilayahBangkalanSeeder::class);

        $this->assertSame(18, Wilayah::where('level', 'kecamatan')->count());
        $this->assertSame(281, Wilayah::where('level', 'desa')->count());
        $this->assertSame('Kamal', Wilayah::where('code', '3526010')->value('name'));
        $this->assertSame('Klampis', Wilayah::where('code', '3526180')->value('name'));
        foreach (Wilayah::where('level', 'desa')->get() as $d) {
            $this->assertSame(10, strlen($d->code));
            $this->assertSame(substr($d->code, 0, 7), $d->parent_code);
        }
        foreach (Wilayah::where('level', 'kecamatan')->pluck('code') as $k) {
            $this->assertGreaterThan(0, Wilayah::where('parent_code', $k)->count(), "kecamatan {$k} punya desa");
        }
    }

    public function test_team_one_pj_per_kecamatan_and_two_enumerators(): void
    {
        $this->seed(WilayahBangkalanSeeder::class);
        $this->seed(PetugasBangkalanSeeder::class);

        $pj = User::where('role', 'pj')->where('active', true)->pluck('wilayah_scope');
        $this->assertSame(18, $pj->count());
        $this->assertSame(18, $pj->unique()->count(), 'satu Penanggung Jawab per kecamatan');
        $this->assertSame('3526010', User::where('username', 'pj_kamal')->value('wilayah_scope'));
        $this->assertGreaterThanOrEqual(36, User::where('role', 'operator')->count());
        $this->assertGreaterThanOrEqual(6, User::where('role', 'pml')->count());

        $a = PetugasBangkalanSeeder::assignment();
        $this->assertCount(18, $a);
        $this->assertSame(User::where('username', 'ppl_ani')->value('id'), $a['3526010']['ppl'][0]);

        // idempoten
        $n = User::count();
        $this->seed(PetugasBangkalanSeeder::class);
        $this->assertSame($n, User::count());
    }
}
