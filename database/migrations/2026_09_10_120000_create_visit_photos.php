<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inc 115 — modul "bukti kunjungan" (opsional, bisa dimatikan lewat
 * config/susenas.php). Foto per rumah tangga sampel, diunggah operator,
 * dilihat pengawas. down() menghapus tabel bersih tanpa menyentuh yang lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_photos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sample_id')->constrained('sample_households')->cascadeOnDelete();
            $t->string('path', 255);
            $t->string('caption', 200)->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedInteger('bytes')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_photos');
    }
};
