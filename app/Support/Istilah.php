<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Terjemahan nilai kode di DB ke teks antarmuka. Nilai DB tetap (error/warning, persiapan,
 * ruta/art/list, int/decimal, …) — hanya tampilannya yang memakai bahasa sehari-hari.
 * Tahap sampel ada di App\Support\Tahap.
 */
final class Istilah
{
    public const SEVERITY = [
        'error' => 'Kesalahan',
        'warning' => 'Peringatan',
    ];

    /** status siklus + dampaknya ke petugas (akses diatur status siklus, lihat Document::editableByPetugas). */
    public const CYCLE_STATUS = [
        'persiapan' => 'Persiapan',
        'lapangan' => 'Lapangan',
        'pengolahan' => 'Pengolahan',
        'selesai' => 'Selesai (terkunci)',
    ];

    public const CYCLE_STATUS_HELP = [
        'persiapan' => 'petugas belum melihat sampel',
        'lapangan' => 'petugas mengisi',
        'pengolahan' => 'petugas hanya melihat, kecuali isian yang dikembalikan',
        'selesai' => 'semua isian terkunci',
    ];

    public const REPEAT = [
        'ruta' => 'per rumah tangga',
        'art' => 'per anggota rumah tangga',
        'list' => 'daftar baris tetap',
        'list_art' => 'daftar × anggota rumah tangga',
    ];

    public const QTYPE = [
        'int' => 'Bilangan bulat',
        'decimal' => 'Angka desimal',
        'code' => 'Pilihan kode',
        'text' => 'Teks',
    ];

    public const SCOPE = [
        'dokumen' => 'Satu sampel',
        'konsistensi' => 'Konsistensi',
        'neraca' => 'Neraca',
        'kalori' => 'Kalori',
        'anomali' => 'Anomali',
    ];

    /** aksi di Log Audit / Riwayat; yang tidak terdaftar dimanusiakan dari kodenya. */
    public const AUDIT_ACTION = [
        'login' => 'Masuk',
        'login-gagal' => 'Gagal masuk',
        'login-terkunci' => 'Akun terkunci sementara',
        'ganti-sandi-sendiri' => 'Ganti kata sandi',
        'ubah-status' => 'Ubah tanda sampel',
        'ganti-sampel' => 'Diganti sampel lain',
        'buat-pengganti' => 'Dibuat sebagai pengganti',
        'tugaskan' => 'Penugasan',
        'tugaskan-massal' => 'Penugasan massal',
        'buat' => 'Dibuat',
        'finalisasi' => 'Dikirim ke pemeriksa',
        'finalisasi-ulang' => 'Dikirim ulang ke pemeriksa',
        'kembalikan' => 'Dikembalikan ke petugas',
        'bersih' => 'Ditandai bersih',
        'bersih-massal' => 'Ditandai bersih (massal)',
        'buka-kunci' => 'Kunci dibuka',
        'koreksi' => 'Koreksi nilai',
        'terima-warning' => 'Peringatan diterima',
        'batal-terima-warning' => 'Penerimaan peringatan dibatalkan',
        'aktifkan' => 'Dijadikan versi bawaan',
        'buka-lapangan' => 'Periode lapangan dibuka',
        'toggle' => 'Aktif/nonaktif diubah',
        'toggle-aktif' => 'Aktif/nonaktif diubah',
        'toggle-massal' => 'Aktif/nonaktif diubah (massal)',
        'sunting-cepat' => 'Sunting cepat',
        'impor-csv' => 'Impor CSV',
        'ekspor-paket' => 'Ekspor paket versi',
        'impor-paket' => 'Impor paket versi',
        'duplikat' => 'Duplikasi',
        'revalidasi' => 'Validasi ulang',
        'unggah' => 'Unggah foto',
    ];

    public const ENTITY = [
        'aggregate' => 'Agregat', 'auth' => 'Masuk sistem', 'block' => 'Blok', 'block_row' => 'Baris tetap',
        'column_alias' => 'Alias kolom impor', 'cycle' => 'Siklus', 'derived' => 'Variabel turunan',
        'document' => 'Isian sampel', 'parameter' => 'Parameter', 'pengolahan' => 'Pengolahan',
        'question' => 'Rincian', 'qversion' => 'Versi kuesioner', 'rule' => 'Aturan validasi',
        'sample' => 'Sampel', 'user' => 'Pengguna', 'visit_photo' => 'Foto kunjungan', 'wilayah' => 'Wilayah',
    ];

    public static function entity(?string $v): string
    {
        return self::ENTITY[$v] ?? (string) $v;
    }

    public static function severity(?string $v): string
    {
        return self::SEVERITY[$v] ?? (string) $v;
    }

    public static function cycleStatus(?string $v): string
    {
        return self::CYCLE_STATUS[$v] ?? (string) $v;
    }

    public static function repeat(?string $v): string
    {
        return self::REPEAT[$v] ?? (string) $v;
    }

    public static function qtype(?string $v): string
    {
        return self::QTYPE[$v] ?? (string) $v;
    }

    public static function scope(?string $v): string
    {
        return self::SCOPE[$v] ?? Str::ucfirst((string) $v);
    }

    public static function audit(?string $v): string
    {
        return self::AUDIT_ACTION[$v] ?? Str::ucfirst(str_replace(['-', '_'], ' ', (string) $v));
    }
}
