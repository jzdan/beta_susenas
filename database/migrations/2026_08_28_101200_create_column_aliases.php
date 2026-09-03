<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Increment 46 — alias kolom impor: pemetaan nama kolom CAPI -> var_name sistem. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('column_aliases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('qversion_id')->constrained('questionnaire_versions')->cascadeOnDelete();
            $t->string('source_col', 128);   // nama kolom pada berkas CAPI (boleh pakai __n)
            $t->string('target_col', 128);   // nama kolom sistem (var_name atau var__n)
            $t->timestamps();
            $t->unique(['qversion_id', 'source_col']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('column_aliases');
    }
};
