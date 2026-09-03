<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Increment 18 — jadwal pencacahan lapangan per siklus untuk pemantauan kecepatan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_cycles', function (Blueprint $t) {
            $t->date('field_start')->nullable()->after('status');
            $t->date('field_end')->nullable()->after('field_start');
        });
    }

    public function down(): void
    {
        Schema::table('survey_cycles', fn (Blueprint $t) => $t->dropColumn(['field_start', 'field_end']));
    }
};
