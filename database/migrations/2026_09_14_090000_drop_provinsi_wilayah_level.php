<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inc 119 — aplikasi ini hanya untuk 1 kabupaten; jenjang "provinsi" pada
 * master wilayah tidak relevan. Kabupaten jadi jenjang teratas (tanpa induk).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('wilayah')->where('level', 'kabupaten')
            ->update(['parent_code' => '']);
        DB::table('wilayah')->where('level', 'provinsi')->delete();
    }

    public function down(): void
    {
        // tidak dapat memulihkan baris provinsi yang sudah dihapus dengan aman.
    }
};
