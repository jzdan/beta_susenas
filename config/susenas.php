<?php

return [
    /*
    | Modul opsional. Matikan (false) untuk menyembunyikan seluruh UI & rute-nya.
    | Data lama tetap tersimpan. Lihat CATATAN-SUSENAS.md untuk cara menghapus
    | modul "bukti kunjungan" sepenuhnya.
    */
    'bukti_kunjungan' => env('SUSENAS_BUKTI_KUNJUNGAN', true),

    // Wajib ada minimal 1 foto sebelum dokumen bisa difinalisasi. Default: tidak.
    'bukti_kunjungan_wajib' => env('SUSENAS_BUKTI_KUNJUNGAN_WAJIB', false),
];
