<?php

namespace App\Models\Concerns;

use App\Models\QuestionnaireVersion;

/**
 * Inc 154 — kuesioner & aturan milik siklus yang sudah dikunci (status `selesai`) hanya-lihat:
 * membuat, mengubah, atau menghapus blok/rincian/opsi/baris/aturan/parameter/agregat/turunan
 * ditolak 423. Dijaga di model supaya berlaku dari semua jalur (formulir, impor, sunting cepat).
 * Untuk mengubahnya, buka kunci siklus dulu (Laporan siklus → Buka kembali kunci).
 * Model pemakai mendefinisikan guardQversionId(): ?int.
 */
trait GuardsLockedVersion
{
    protected static function bootGuardsLockedVersion(): void
    {
        $guard = function ($m) {
            $id = $m->guardQversionId();
            if ($id && QuestionnaireVersion::isLocked($id)) {
                abort(423, 'Kuesioner ini milik periode yang sudah dikunci, jadi hanya bisa dilihat. Buka kunci periodenya dulu untuk mengubah.');
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }
}
