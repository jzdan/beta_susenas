<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Parameter extends Model
{
    public $timestamps = false;

    protected $fillable = ['qversion_id', 'name', 'value', 'note'];

    /**
     * Parameter sistem (Inc 145): nama variabel yang dipakai PROGRAM untuk fitur tertentu.
     * Nilainya daftar nama variabel dipisah koma — yang pertama ada di versi itu yang dipakai.
     * Bila parameter belum dibuat di suatu versi, dipakai `default` (= perilaku lama),
     * jadi versi lama tetap jalan tanpa diubah. Ganti nama variabel di kuesioner baru →
     * cukup buat/ubah parameter ini di tab Neraca & Turunan, tanpa ubah program.
     */
    public const SYSTEM = [
        'var_jumlah_art' => [
            'default' => 'banyak_art,r301,jml_art,jumlah_art',
            'label' => 'Variabel "Banyaknya ART" (tombol Terapkan jumlah ART & jumlah baris roster)',
        ],
        'var_pengeluaran_per_kapita' => [
            'default' => 'pengeluaran_per_kapita_bulan,pengeluaran_per_kapita',
            'label' => 'Variabel pengeluaran per kapita sebulan (Indikator Kesejahteraan)',
        ],
        'var_makanan_sebulan' => [
            'default' => 'rekap_makanan_sebulan',
            'label' => 'Variabel pengeluaran makanan sebulan (pangsa makanan, Indikator)',
        ],
        'var_ruta_sebulan' => [
            'default' => 'rekap_ruta_sebulan',
            'label' => 'Variabel total pengeluaran rumah tangga sebulan (pangsa makanan, Indikator)',
        ],
    ];

    /** Daftar kandidat nama variabel untuk parameter sistem $name di versi $qvId. */
    public static function systemVars(?int $qvId, string $name): array
    {
        $raw = $qvId ? static::where('qversion_id', $qvId)->where('name', $name)->value('value') : null;
        $raw = trim((string) ($raw ?? self::SYSTEM[$name]['default'] ?? ''));

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== ''));
    }
}
