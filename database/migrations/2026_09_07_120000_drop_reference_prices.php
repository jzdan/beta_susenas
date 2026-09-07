<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inc 107 — hapus lapisan override harga per wilayah. Aplikasi hanya untuk satu
 * kabupaten, jadi satu rentang harga per komoditas (di block_rows.ref) sudah
 * cukup dan dapat disunting di menu Harga Referensi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('reference_prices');
    }

    public function down(): void
    {
        Schema::create('reference_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('qversion_id')->constrained('questionnaire_versions')->cascadeOnDelete();
            $t->string('code', 16);
            $t->string('wilayah_prefix', 16)->default('');
            $t->decimal('harga_min', 14, 2)->nullable();
            $t->decimal('harga_max', 14, 2)->nullable();
            $t->timestamps();
            $t->unique(['qversion_id', 'code', 'wilayah_prefix']);
        });
    }
};
