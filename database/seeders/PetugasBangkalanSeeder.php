<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wilayah;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Inc 165 — tim lapangan contoh untuk 18 kecamatan Bangkalan (nama rekaan):
 *   - 1 Penanggung Jawab per kecamatan (pj_<kecamatan>; Kamal = pj_kamal bawaan)
 *   - 6 Pengawas Lapangan, masing-masing 3 kecamatan berurutan (rahmawati, santoso bawaan + pml_03..06)
 *   - 2 Pencacah Lapangan per kecamatan (ppl_<kecamatan>_1/_2; Kamal = ppl_ani & ppl_dodi, Labang 1 = ppl_eka)
 * Sandi mengikuti pola akun bawaan: pj123, pengawas123, operator123.
 * Butuh WilayahBangkalanSeeder lebih dulu. Dipanggil DatabaseSeeder di luar tes; idempoten.
 */
class PetugasBangkalanSeeder extends Seeder
{
    public const PML = ['rahmawati', 'santoso', 'pml_03', 'pml_04', 'pml_05', 'pml_06'];

    private const FIRST = ['Achmad', 'Moh.', 'Abdul', 'Siti', 'Nur', 'Fatimah', 'Hasan', 'Ainur', 'Rofiq', 'Lailatul',
        'Imam', 'Dewi', 'Syaiful', 'Anis', 'Ridwan', 'Maimunah', 'Taufik', 'Ulfa', 'Hendra', 'Khoirul', 'Rina', 'Yanto',
        'Badrus', 'Novi', 'Faisal', 'Zainab', 'Wahyu', 'Halimatus', 'Fikri', 'Indah'];

    private const LAST = ['Hidayat', 'Rahman', 'Fauzi', 'Hasanah', 'Mubarok', 'Sholeh', 'Qomariyah', 'Anwar', 'Ismail',
        'Rohmah', 'Hakim', 'Safitri', 'Arifin', 'Mufida', 'Bahri', 'Yusuf', 'Kurniawan', 'Aminah', 'Syafi\'i', 'Maulana'];

    /** "Tanah Merah" → "tanah_merah" */
    public static function slug(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
    }

    /** kode kecamatan (urut) => [pj, pml, [ppl_1, ppl_2]] — nama pengguna. */
    public static function plan(): array
    {
        $out = [];
        foreach (Wilayah::where('level', 'kecamatan')->orderBy('code')->get() as $i => $k) {
            $s = self::slug($k->name);
            $ppl = ["ppl_{$s}_1", "ppl_{$s}_2"];
            if ($k->code === '3526010') {
                $ppl = ['ppl_ani', 'ppl_dodi'];
            } elseif ($k->code === '3526020') {
                $ppl[0] = 'ppl_eka';
            }
            $out[$k->code] = ['pj' => "pj_{$s}", 'pml' => self::PML[intdiv($i, 3) % count(self::PML)], 'ppl' => $ppl,
                'name' => $k->name];
        }

        return $out;
    }

    /** Sama dengan plan(), tetapi berisi id pengguna; [] bila tim belum dibuat (mis. dataset tes). */
    public static function assignment(): array
    {
        $plan = self::plan();
        $ids = User::pluck('id', 'username');
        $out = [];
        foreach ($plan as $kec => $p) {
            if (! isset($ids[$p['pj']], $ids[$p['pml']], $ids[$p['ppl'][0]], $ids[$p['ppl'][1]])) {
                return [];
            }
            $out[$kec] = ['pj' => $ids[$p['pj']], 'pml' => $ids[$p['pml']],
                'ppl' => [$ids[$p['ppl'][0]], $ids[$p['ppl'][1]]]];
        }

        return $out;
    }

    public function run(): void
    {
        $hash = ['pj' => Hash::make('pj123'), 'pml' => Hash::make('pengawas123'), 'operator' => Hash::make('operator123')];
        $seq = 0;
        $made = 0;
        $make = function (string $username, string $role, string $scope, ?string $name = null) use ($hash, &$seq, &$made) {
            if (User::where('username', $username)->exists()) {
                return;
            }
            $seq++;
            $name ??= self::FIRST[($seq * 7) % count(self::FIRST)].' '.self::LAST[($seq * 3) % count(self::LAST)];
            $u = new User;
            $u->forceFill(['username' => $username, 'name' => $name, 'email' => "{$username}@example.test",
                'password' => $hash[$role], 'role' => $role, 'wilayah_scope' => $scope, 'active' => true]);
            $u->saveQuietly();
            $made++;
        };
        foreach (array_slice(self::PML, 2) as $pml) {
            $make($pml, 'pml', '');
        }
        foreach (self::plan() as $kec => $p) {
            $make($p['pj'], 'pj', $kec);
            foreach ($p['ppl'] as $ppl) {
                $make($ppl, 'operator', '');
            }
        }
        $this->command?->info("Petugas Bangkalan: {$made} akun baru.");
    }
}
