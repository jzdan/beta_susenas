<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Inc 158 — riwayat perubahan aturan validasi (+ kembalikan ke revisi lama)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rule_id')->constrained('validation_rules')->cascadeOnDelete();
            $t->string('action', 30);            // awal | buat | ubah | sunting-cepat | nyalakan | matikan | kembalikan
            $t->json('data');                    // keadaan aturan SESUDAH perubahan (awal = sebelum perubahan pertama)
            $t->foreignId('by_user')->nullable();
            $t->timestamp('at');
            $t->index(['rule_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_revisions');
    }
};
