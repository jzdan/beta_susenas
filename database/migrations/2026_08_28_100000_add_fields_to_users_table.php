<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->unique()->after('id');
            $table->string('role', 16)->default('viewer')->after('password'); // admin|pml|operator|viewer
            $table->string('wilayah_scope', 16)->default('')->after('role');   // prefiks kode wilayah; '' = semua
            $table->boolean('active')->default(true)->after('wilayah_scope');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'role', 'wilayah_scope', 'active']);
        });
    }
};
