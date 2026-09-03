<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metadata kuesioner — struktur & aturan disimpan sebagai DATA, bertag versi.
 * Kuesioner periode baru = baris konfigurasi baru, bukan perubahan kode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_cycles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 128);
            $t->string('period', 16);            // "2027-03"
            $t->string('status', 16)->default('persiapan');
            $t->timestamps();
        });

        Schema::create('questionnaire_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cycle_id')->constrained('survey_cycles')->cascadeOnDelete();
            $t->string('code', 32);              // "VSEN27.KP"
            $t->string('effective_date', 16);
            $t->timestamps();
        });

        Schema::create('blocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('qversion_id')->constrained('questionnaire_versions')->cascadeOnDelete();
            $t->string('code', 16);              // "IV.2"
            $t->string('title', 255);
            $t->integer('order')->default(0);
            $t->string('repeat', 8)->default('ruta');   // ruta | art
            $t->timestamps();
        });

        Schema::create('questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('block_id')->constrained('blocks')->cascadeOnDelete();
            $t->string('code_rincian', 16);
            $t->string('var_name', 64);
            $t->string('label', 400);
            $t->string('qtype', 16)->default('int');   // int|decimal|code|text
            $t->string('unit', 32)->default('');
            $t->integer('order')->default(0);
            $t->string('skip_expr', 500)->default('');   // ekspresi lompat (kosong = tak ada)
            $t->string('help_text', 400)->default('');
            $t->timestamps();
            $t->unique(['block_id', 'var_name']);
        });

        Schema::create('response_options', function (Blueprint $t) {
            $t->id();
            $t->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $t->string('code', 16);
            $t->string('label', 255);
            $t->integer('order')->default(0);
        });

        Schema::create('parameters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('qversion_id')->constrained('questionnaire_versions')->cascadeOnDelete();
            $t->string('name', 64);
            $t->string('value', 64);
            $t->string('note', 255)->default('');
        });

        Schema::create('derived_fields', function (Blueprint $t) {
            $t->id();
            $t->foreignId('qversion_id')->constrained('questionnaire_versions')->cascadeOnDelete();
            $t->string('var_name', 64);
            $t->string('label', 255);
            $t->text('expr');
            $t->integer('order')->default(0);
        });

        Schema::create('validation_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('qversion_id')->constrained('questionnaire_versions')->cascadeOnDelete();
            $t->string('code', 32);
            $t->string('scope', 32)->default('dokumen');
            $t->string('target_var', 64)->default('');
            $t->text('expr');
            $t->string('severity', 16)->default('warning');  // error | warning
            $t->string('message', 400);
            $t->boolean('active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_rules');
        Schema::dropIfExists('derived_fields');
        Schema::dropIfExists('parameters');
        Schema::dropIfExists('response_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('questionnaire_versions');
        Schema::dropIfExists('survey_cycles');
    }
};
