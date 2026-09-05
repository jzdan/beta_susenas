<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Peran "editor" dihapus — izinnya (pembersihan/pengolahan + tabulasi) sudah
 * tercakup peran "pml". Pengguna lama berperan editor dipindah ke pml.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'editor')->update(['role' => 'pml']);
    }

    public function down(): void
    {
        // tidak bisa dipulihkan otomatis — peran editor sudah tidak ada
    }
};
