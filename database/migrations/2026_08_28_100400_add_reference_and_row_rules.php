<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment 5 — data referensi per-komoditas + validasi per-baris.
 *
 * - block_rows.ref  : JSON data referensi per baris (kalori, harga_min, harga_max, ...)
 * - aggregates.weight_ref : kunci di block_rows.ref untuk dikali tiap baris (op weighted_sum)
 * - validation_rules.row_block : bila diisi kode blok, aturan dievaluasi PER BARIS
 *   blok itu, dengan konteks lokal (nilai kolom baris + ref_<key>)
 * - rule_results.row_index : nomor baris (0 = tingkat dokumen)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('block_rows', function (Blueprint $t) {
            $t->json('ref')->nullable();
        });
        Schema::table('aggregates', function (Blueprint $t) {
            $t->string('weight_ref', 32)->default('')->after('op');
        });
        Schema::table('validation_rules', function (Blueprint $t) {
            $t->string('row_block', 16)->default('')->after('scope');
        });
        Schema::table('rule_results', function (Blueprint $t) {
            $t->integer('row_index')->default(0)->after('target_var');
        });
    }

    public function down(): void
    {
        Schema::table('block_rows', fn (Blueprint $t) => $t->dropColumn('ref'));
        Schema::table('aggregates', fn (Blueprint $t) => $t->dropColumn('weight_ref'));
        Schema::table('validation_rules', fn (Blueprint $t) => $t->dropColumn('row_block'));
        Schema::table('rule_results', fn (Blueprint $t) => $t->dropColumn('row_index'));
    }
};
