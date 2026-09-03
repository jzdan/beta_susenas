<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment 48 — rentang harga wajar komoditas per wilayah.
 * Menimpa harga_min/harga_max default dari block_rows.ref untuk baris IV.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('qversion_id')->constrained('questionnaire_versions')->cascadeOnDelete();
            $t->string('code', 16);                 // kode komoditas (block_rows.code)
            $t->string('wilayah_prefix', 16)->default('');   // '' = nasional; '3526' = kab; dst.
            $t->decimal('harga_min', 14, 2)->nullable();
            $t->decimal('harga_max', 14, 2)->nullable();
            $t->timestamps();
            $t->unique(['qversion_id', 'code', 'wilayah_prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_prices');
    }
};
