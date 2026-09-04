<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keterangan bahasa manusia untuk tiap aturan validasi — ditampilkan di
 * halaman "Aturan Validasi" agar petugas paham maksud aturan tanpa membaca
 * ekspresi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validation_rules', function (Blueprint $t) {
            $t->text('note')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('validation_rules', fn (Blueprint $t) => $t->dropColumn('note'));
    }
};
