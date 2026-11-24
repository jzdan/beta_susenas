<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inc 153 — kapan aturan/parameter/agregat/turunan/patokan harga suatu versi terakhir diubah.
 * Sampel yang snapshot-nya lebih tua dari waktu ini dianggap "belum diperiksa ulang".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questionnaire_versions', function (Blueprint $t) {
            $t->timestamp('rules_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('questionnaire_versions', function (Blueprint $t) {
            $t->dropColumn('rules_changed_at');
        });
    }
};
