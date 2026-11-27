<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Inc 167 — panduan yang sudah pernah muncul sendiri untuk seorang pengguna (per versi isi). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guide_views', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('page_key', 60);
            $t->string('version', 20);
            $t->timestamp('seen_at')->useCurrent();
            $t->unique(['user_id', 'page_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_views');
    }
};
