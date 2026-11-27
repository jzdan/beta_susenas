<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Inc 167 — panduan alur kerja: langkah punya label bagian (A. Sekali di awal …) & tautan "Buka halaman ini". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guide_slides', function (Blueprint $t) {
            $t->string('section', 120)->nullable()->after('order');
            $t->string('link', 255)->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('guide_slides', function (Blueprint $t) {
            $t->dropColumn(['section', 'link']);
        });
    }
};
