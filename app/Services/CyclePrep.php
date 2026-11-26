<?php

namespace App\Services;

use App\Models\Block;
use App\Models\BlockRow;
use App\Models\QuestionnaireVersion;
use App\Models\SurveyCycle;
use App\Models\User;
use App\Models\ValidationRule;
use App\Models\Wilayah;

/**
 * Daftar periksa "Persiapan periode": apa saja yang harus beres sebelum siklus dibuka untuk
 * lapangan (status `lapangan` = petugas mulai mengisi, lihat Document::editableByPetugas).
 *
 * Butir `wajib` menahan pembukaan lapangan (admin masih bisa memaksa, tercatat di log audit);
 * butir lain hanya peringatan. Pemeriksaan keutuhan versi (VersionChecker) dijalankan di sini —
 * dulu di tombol "Jadikan aktif", tetapi sejak Inc 148 versi bawaan tidak memengaruhi petugas.
 */
class CyclePrep
{
    public function __construct(private VersionChecker $checker) {}

    /** Versi yang dipakai isian baru siklus ini (sama dengan MonitoringController::createDocument). */
    public static function versionFor(SurveyCycle $cycle): ?QuestionnaireVersion
    {
        return QuestionnaireVersion::where('cycle_id', $cycle->id)->orderByDesc('id')->first();
    }

    /**
     * @return array{items: list<array{key:string,label:string,ok:bool,wajib:bool,detail:string,url:?string,link:?string}>,
     *               siap: bool, version: ?QuestionnaireVersion, versionErrors: list<string>}
     */
    public function check(SurveyCycle $cycle): array
    {
        $items = [];
        $add = function (string $key, string $label, bool $ok, bool $wajib, string $detail = '', ?string $url = null, ?string $link = null) use (&$items) {
            $items[] = compact('key', 'label', 'ok', 'wajib', 'detail', 'url', 'link');
        };

        $qv = self::versionFor($cycle);
        $add('versi', 'Versi kuesioner tersedia', (bool) $qv, true,
            $qv ? "Isian baru memakai versi {$qv->code}." : 'Belum ada versi kuesioner untuk siklus ini.',
            route('admin.metadata', $qv ? ['qv' => $qv->id] : []), 'Metadata');

        $versionErrors = [];
        if ($qv) {
            $res = $this->checker->check($qv);
            $versionErrors = $res['errors'];
            $add('periksa', 'Versi kuesioner utuh (tidak ada rujukan putus)', ! $versionErrors, true,
                $versionErrors ? count($versionErrors).' rujukan putus, mis. '.$versionErrors[0]
                    : ($res['warnings'] ? count($res['warnings']).' catatan ringan, tidak menahan.' : 'Semua aturan, agregat, dan turunan menunjuk variabel yang ada.'),
                route('admin.metadata', ['qv' => $qv->id]), 'Periksa di Metadata');

            $nRules = ValidationRule::where('qversion_id', $qv->id)->where('active', true)->count();
            $add('aturan', 'Aturan validasi menyala', $nRules > 0, false,
                "{$nRules} aturan menyala.", route('admin.aturan', ['qv' => $qv->id]), 'Aturan Validasi');

            $hk = Block::where('qversion_id', $qv->id)->where('harga_kalori', true)->pluck('id');
            if ($hk->isNotEmpty()) {
                $rows = BlockRow::whereIn('block_id', $hk)->get(['ref']);
                $kosong = $rows->filter(fn ($r) => ! isset(((array) $r->ref)['harga_min'], ((array) $r->ref)['harga_max']))->count();
                $add('harga', 'Patokan harga komoditas terisi', $kosong === 0, false,
                    $kosong ? "{$kosong} dari {$rows->count()} komoditas belum punya rentang harga." : "{$rows->count()} komoditas lengkap.",
                    route('admin.aturan', ['qv' => $qv->id, 'tab' => 'harga']), 'Harga & Kalori');
            }
        }

        $samples = $cycle->samples()->where('status', '!=', 'ganti_sampel')->get(['id', 'wilayah_code', 'petugas_id', 'pml_id']);
        $n = $samples->count();
        $add('sampel', 'Sampel sudah dimuat', $n > 0, true, "{$n} sampel.", route('admin.impor.sampel'), 'Impor Sampel');

        if ($n > 0) {
            $tanpaPetugas = $samples->whereNull('petugas_id')->count();
            $add('petugas', 'Semua sampel punya Pencacah Lapangan', $tanpaPetugas === 0, true,
                $tanpaPetugas ? "{$tanpaPetugas} sampel belum ditugaskan." : 'Semua sudah ditugaskan.',
                route('monitoring.samples', ['cycle' => $cycle->id, 'petugas' => 'none']), 'Daftar Sampel');

            $tanpaPml = $samples->whereNull('pml_id')->count();
            $add('pml', 'Semua sampel punya Pengawas Lapangan', $tanpaPml === 0, false,
                $tanpaPml ? "{$tanpaPml} sampel belum punya pengawas." : 'Semua sudah punya pengawas.',
                route('monitoring.samples', ['cycle' => $cycle->id]), 'Daftar Sampel');

            $ids = $samples->pluck('petugas_id')->filter()->unique();
            $nonaktif = $ids->isEmpty() ? 0 : User::whereIn('id', $ids)->where('active', false)->count();
            $add('akun', 'Akun petugas yang ditugaskan aktif', $nonaktif === 0, false,
                $nonaktif ? "{$nonaktif} akun petugas nonaktif masih memegang sampel." : "{$ids->count()} petugas, semua aktif.",
                route('admin.pengguna'), 'Pengguna');

            $codes = $samples->pluck('wilayah_code')->unique();
            $dikenal = Wilayah::whereIn('code', $codes)->count();
            $asing = $codes->count() - $dikenal;
            $add('wilayah', 'Kode wilayah sampel terdaftar', $asing === 0, false,
                $asing ? "{$asing} kode desa sampel tidak ada di daftar wilayah." : "{$codes->count()} desa, semua terdaftar.",
                route('admin.impor.wilayah'), 'Wilayah');
        }

        $add('jadwal', 'Tanggal lapangan diisi', $cycle->field_start && $cycle->field_end, false,
            $cycle->field_start && $cycle->field_end
                ? $cycle->field_start->format('d-m-Y').' s.d. '.$cycle->field_end->format('d-m-Y').'.'
                : 'Dipakai dashboard untuk target kemajuan harian.',
            route('admin.jadwal'), 'Jadwal');

        // Inc 163: jadwal harus jatuh di periodenya (wajib); triwulan yang belum mulai = peringatan saja
        $why = $cycle->scheduleIssue();
        $add('jadwal_periode', 'Jadwal lapangan sesuai periode', $why === null, true,
            $why ?? 'Tanggal lapangan berada di triwulan periode ini.', route('admin.jadwal'), 'Jadwal');
        $belum = $cycle->quarterNotStarted();
        $range = SurveyCycle::quarterRange((string) $cycle->period);
        $add('triwulan_mulai', 'Triwulan periode ini sudah dimulai', ! $belum, false,
            $belum ? 'Triwulan ini baru mulai '.$range[0]->format('d-m-Y').'. Membuka lapangan sekarang berarti petugas bisa mengisi lebih awal.'
                : 'Sesuai kalender.');

        $siap = collect($items)->every(fn ($i) => $i['ok'] || ! $i['wajib']);

        return ['items' => $items, 'siap' => $siap, 'version' => $qv, 'versionErrors' => $versionErrors];
    }

    /** Ringkas butir wajib yang belum beres, untuk pesan penolakan. */
    public static function blockers(array $result): array
    {
        return collect($result['items'])->filter(fn ($i) => $i['wajib'] && ! $i['ok'])
            ->map(fn ($i) => $i['label'].': '.$i['detail'])->values()->all();
    }
}
