<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inc 145 — blocks.harga_kalori: menandai blok daftar komoditas yang patokan
 * harga & kalorinya disunting di tab "Harga & Kalori" (Aturan Validasi).
 * Sebelumnya program mencari blok berkode 'IV.1' secara langsung; dengan tanda ini
 * blok komoditas boleh berkode apa saja (mis. 'V' di kuesioner mendatang).
 * Blok IV.1 yang sudah ada ditandai otomatis supaya perilaku lama tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blocks', function (Blueprint $t) {
            $t->boolean('harga_kalori')->default(false);
        });
        DB::table('blocks')->where('code', 'IV.1')->update(['harga_kalori' => true]);
    }

    public function down(): void
    {
        Schema::table('blocks', fn (Blueprint $t) => $t->dropColumn('harga_kalori'));
    }
};
