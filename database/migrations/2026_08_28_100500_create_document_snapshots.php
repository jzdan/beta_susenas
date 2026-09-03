<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment 7 — tabel datar termaterialisasi.
 *
 * Setiap kali RuleEngine::run() jalan, seluruh konteks skalar dokumen (jawaban
 * ruta + parameter + agregat + turunan + kalori) disimpan sebagai JSON di sini.
 * Tabulasi & ekspor membaca snapshot ini, bukan menelusuri tabel answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->unique()->constrained('documents')->cascadeOnDelete();
            $t->json('data');
            $t->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_snapshots');
    }
};
