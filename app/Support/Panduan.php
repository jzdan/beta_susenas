<?php

namespace App\Support;

/**
 * Daftar halaman yang punya panduan bergambar (Inc 167). Kunci = page_key di tabel guide_slides.
 * Tambah halaman baru: tambahkan entri di sini, lalu pasang <x-panduan key="…" /> di view-nya.
 */
final class Panduan
{
    /** @return array<string, array{label:string, route:string, params?:array}> */
    public static function pages(): array
    {
        return [
            'alur-kerja' => ['label' => 'Alur kerja admin', 'route' => 'panduan.alur'],
            'jadwal' => ['label' => 'Periode & Jadwal', 'route' => 'admin.jadwal'],
            'persiapan' => ['label' => 'Persiapan periode', 'route' => 'periode.index'],
            'wilayah' => ['label' => 'Daftar Wilayah', 'route' => 'admin.impor.wilayah'],
            'impor-sampel' => ['label' => 'Impor Sampel', 'route' => 'admin.impor.sampel'],
            'impor-isian' => ['label' => 'Impor Isian dari CAPI', 'route' => 'admin.impor.dokumen'],
            'impor-rincian' => ['label' => 'Impor Rincian', 'route' => 'admin.impor'],
            'aturan' => ['label' => 'Aturan Validasi', 'route' => 'admin.aturan'],
            'aturan-form' => ['label' => 'Membuat aturan validasi', 'route' => 'admin.aturan.baru'],
            'angka-bantu' => ['label' => 'Angka bantu', 'route' => 'admin.aturan', 'params' => ['tab' => 'neraca']],
            'harga-kalori' => ['label' => 'Harga & Kalori', 'route' => 'admin.aturan', 'params' => ['tab' => 'harga']],
            'tabulasi' => ['label' => 'Tabulasi & Ekspor', 'route' => 'tabulasi.index'],
            'indikator' => ['label' => 'Indikator Kesejahteraan', 'route' => 'tabulasi.indikator'],
        ];
    }

    public static function label(string $key): string
    {
        return self::pages()[$key]['label'] ?? $key;
    }

    public static function exists(string $key): bool
    {
        return isset(self::pages()[$key]);
    }

    public static function url(string $key): ?string
    {
        $p = self::pages()[$key] ?? null;

        return $p ? route($p['route'], $p['params'] ?? []) : null;
    }
}
