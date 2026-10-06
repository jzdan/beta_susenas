<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Data operasional: wilayah, sampel, dokumen, jawaban, jejak, audit. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wilayah', function (Blueprint $t) {
            $t->id();
            $t->string('code', 16)->unique();
            $t->string('name', 128);
            $t->string('level', 16);            // kabupaten|kecamatan|desa (kabupaten = jenjang teratas, aplikasi 1 kabupaten)
            $t->string('parent_code', 16)->default('');
        });

        Schema::create('sample_households', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cycle_id')->constrained('survey_cycles')->cascadeOnDelete();
            $t->string('wilayah_code', 16);     // kode desa
            $t->string('no_bs', 8);
            $t->string('no_ruta', 8);
            $t->string('krt_name', 128)->default('');
            $t->foreignId('petugas_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('pml_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 16)->default('belum'); // belum|proses|selesai|non_respons|ganti_sampel
            $t->timestamps();
            $t->unique(['cycle_id', 'wilayah_code', 'no_bs', 'no_ruta']);
        });

        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sample_id')->constrained('sample_households')->cascadeOnDelete();
            $t->foreignId('qversion_id')->constrained('questionnaire_versions')->cascadeOnDelete();
            $t->string('status', 16)->default('draft'); // draft|selesai|bersih
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('answers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $t->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $t->integer('art_index')->default(0);   // 0 = tingkat ruta; 1..N = nomor ART
            $t->string('raw_value', 255)->default('');
            $t->string('clean_value', 255)->nullable();
            $t->timestamps();
            $t->unique(['document_id', 'question_id', 'art_index']);
        });

        Schema::create('answer_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $t->unsignedBigInteger('question_id');
            $t->integer('art_index')->default(0);
            $t->string('old_value', 255)->default('');
            $t->string('new_value', 255)->default('');
            $t->foreignId('by_user')->nullable()->constrained('users')->nullOnDelete();
            $t->string('reason', 255)->default('');
            $t->timestamp('at')->useCurrent();
        });

        Schema::create('rule_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $t->unsignedBigInteger('rule_id');
            $t->string('status', 16);           // error|warning|ok
            $t->string('message', 400)->default('');
            $t->string('target_var', 64)->default('');
            $t->timestamp('computed_at')->useCurrent();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('entity', 32);
            $t->string('entity_id', 32)->default('');
            $t->string('action', 32);
            $t->string('detail', 400)->default('');
            $t->foreignId('by_user')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('rule_results');
        Schema::dropIfExists('answer_histories');
        Schema::dropIfExists('answers');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('sample_households');
        Schema::dropIfExists('wilayah');
    }
};
