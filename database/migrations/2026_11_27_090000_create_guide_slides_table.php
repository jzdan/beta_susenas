<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Inc 167 — panduan bergambar (carousel) per halaman admin; isinya dikelola admin di web. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guide_slides', function (Blueprint $t) {
            $t->id();
            $t->string('page_key', 60)->index();   // kunci halaman, lihat App\Support\Panduan::pages()
            $t->unsignedInteger('order')->default(0);
            $t->string('title', 150);
            $t->text('body')->nullable();
            $t->string('image_path')->nullable();  // di disk 'local' (storage/app/private/panduan/…)
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_slides');
    }
};
