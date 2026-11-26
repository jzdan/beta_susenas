<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Inc 161 — pekerjaan latar belakang (periksa ulang massal, hitung aturan setelah impor besar)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('background_tasks', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 40);                 // periksa-ulang | impor-isian
            $t->string('label');
            $t->foreignId('qversion_id')->nullable();
            $t->string('status', 20)->default('antre');   // antre | berjalan | selesai | gagal
            $t->unsignedInteger('done')->default(0);
            $t->unsignedInteger('total')->default(0);
            $t->text('message')->nullable();
            $t->foreignId('by_user')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->index(['qversion_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('background_tasks');
    }
};
