<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment 56 — catatan bebas per dokumen: utas komunikasi ringan
 * operator ↔ pemeriksa tanpa harus mengembalikan dokumen (ubah status).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $t->text('body');
            $t->foreignId('by_user')->constrained('users');
            $t->timestamps();
            $t->index(['document_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_notes');
    }
};
