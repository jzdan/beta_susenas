<?php

namespace App\Support;

use App\Models\Document;
use App\Models\SampleHousehold;
use Illuminate\Database\Eloquent\Builder;

/**
 * Satu urutan tahap yang dilihat pengguna untuk sebuah sampel — gabungan status sampel
 * (`sample_households.status`) dan status isiannya (`documents.status`). Kolom DB tetap dua;
 * kelas ini hanya menerjemahkannya supaya antarmuka tidak menampilkan dua status berbeda.
 *
 * Tahap berjalan otomatis (buka sampel → diisi, finalisasi → menunggu pemeriksaan, kembalikan,
 * tandai bersih). Yang diubah tangan oleh pengawas hanya non-respons & ganti sampel.
 */
final class Tahap
{
    public const LABELS = [
        'belum' => 'Belum dikunjungi',
        'diisi' => 'Sedang diisi',
        'diperiksa' => 'Menunggu pemeriksaan',
        'dikembalikan' => 'Dikembalikan ke petugas',
        'bersih' => 'Bersih',
        'non_respons' => 'Non-respons',
        'ganti_sampel' => 'Diganti sampel lain',
    ];

    /** status isian → tahap */
    public const FROM_DOC = [
        'draft' => 'diisi',
        'selesai' => 'diperiksa',
        'dikembalikan' => 'dikembalikan',
        'bersih' => 'bersih',
    ];

    /** status sampel yang ditandai manual oleh pengawas (mengalahkan status isian). */
    public const MANUAL = ['non_respons', 'ganti_sampel'];

    public static function of(SampleHousehold $sample, ?Document $doc = null): string
    {
        if (in_array($sample->status, self::MANUAL, true)) {
            return $sample->status;
        }

        return $doc ? self::ofDocument($doc) : 'belum';
    }

    public static function ofDocument(Document $doc): string
    {
        return self::FROM_DOC[$doc->status] ?? 'diisi';
    }

    public static function label(string $tahap): string
    {
        return self::LABELS[$tahap] ?? $tahap;
    }

    /** Batasi query sampel ke satu tahap (dipakai filter Daftar Sampel). */
    public static function filter(Builder $q, string $tahap): void
    {
        if (in_array($tahap, self::MANUAL, true)) {
            $q->where('status', $tahap);
        } elseif ($tahap === 'belum') {
            $q->whereNotIn('status', self::MANUAL)->doesntHave('documents');
        } elseif ($docStatus = array_search($tahap, self::FROM_DOC, true)) {
            $q->whereNotIn('status', self::MANUAL)
                ->whereHas('documents', fn ($d) => $d->where('status', $docStatus));
        }
    }

    /** Status sampel otomatis menurut isiannya — dipakai saat tanda manual dibatalkan. */
    public static function autoSampleStatus(SampleHousehold $sample): string
    {
        $doc = $sample->documents()->first();
        if (! $doc) {
            return 'belum';
        }

        return in_array($doc->status, ['selesai', 'bersih'], true) ? 'selesai' : 'proses';
    }
}
