<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Increment 13 — agregat bisa disaring berdasarkan nilai referensi baris (mis. kelompok komoditas). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aggregates', function (Blueprint $t) {
            $t->string('filter_ref', 32)->default('')->after('filter_val');
            $t->string('filter_ref_val', 64)->default('')->after('filter_ref');
        });
    }

    public function down(): void
    {
        Schema::table('aggregates', fn (Blueprint $t) => $t->dropColumn(['filter_ref', 'filter_ref_val']));
    }
};
