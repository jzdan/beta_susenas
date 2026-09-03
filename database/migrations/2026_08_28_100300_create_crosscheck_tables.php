<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment 6 — cek-silang gaya NERACA.
 *
 * - block_rows : baris tetap untuk blok bertipe repeat='list' (mis. daftar
 *   komoditas Blok IV.1). Berbeda dari 'art' yang jumlah barisnya dinamis.
 * - aggregates : konfigurasi agregasi terkelompok atas satu blok berulang
 *   (mis. "jumlah nilai kolom X dari blok IV.1 yang kolom asal-nya = 2").
 *   Hasilnya disuntikkan sebagai skalar ke konteks ekspresi, sehingga aturan
 *   NERACA cukup ditulis sebagai ekspresi biasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('block_id')->constrained('blocks')->cascadeOnDelete();
            $t->integer('row_index');       // 1..M, dipakai sebagai answers.art_index
            $t->string('code', 24)->default('');
            $t->string('label', 255);
            $t->integer('order')->default(0);
            $t->unique(['block_id', 'row_index']);
        });

        Schema::create('aggregates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('qversion_id')->constrained('questionnaire_versions')->cascadeOnDelete();
            $t->string('name', 64);         // nama variabel yang disuntik ke konteks
            $t->string('label', 255)->default('');
            $t->string('block_code', 16);   // blok berulang yang dipindai
            $t->string('value_var', 64);    // kolom yang diagregasi
            $t->string('op', 12)->default('sum');       // sum | count | max | min
            $t->string('filter_var', 64)->default('');  // kolom penyaring (opsional)
            $t->string('filter_op', 8)->default('any'); // any | eq | in | ne
            $t->string('filter_val', 64)->default('');  // daftar kode dipisah koma
            $t->integer('order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aggregates');
        Schema::dropIfExists('block_rows');
    }
};
