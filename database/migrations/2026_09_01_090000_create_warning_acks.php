<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment 54 — "Terima warning": pemeriksa menandai sebuah peringatan
 * sudah ditinjau (bukan anomali) beserta catatan. Peringatan yang sudah
 * diterima tidak lagi dihitung sebagai "belum diperiksa" saat cleaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warning_acks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $t->string('rule_code', 40);
            $t->string('target_var', 60)->default('');
            $t->integer('row_index')->default(0);
            $t->string('note', 500)->nullable();
            $t->foreignId('by_user')->constrained('users');
            $t->timestamps();
            $t->unique(['document_id', 'rule_code', 'target_var', 'row_index'], 'warning_acks_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warning_acks');
    }
};
