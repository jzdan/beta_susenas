<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Inc 159 — contoh kasus uji per aturan: [{name, isian:"var=nilai; …", expect:bool}]
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validation_rules', function (Blueprint $t) {
            $t->json('test_cases')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('validation_rules', function (Blueprint $t) {
            $t->dropColumn('test_cases');
        });
    }
};
