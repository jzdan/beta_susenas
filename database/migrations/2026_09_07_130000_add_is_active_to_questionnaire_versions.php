<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inc 108 — versi kuesioner "aktif" (yang disunting di menu Admin) dipilih
 * eksplisit, bukan sekadar "id terbesar". Nilai awal = versi id terbesar
 * supaya perilaku tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questionnaire_versions', function (Blueprint $t) {
            $t->boolean('is_active')->default(false);
        });
        $id = DB::table('questionnaire_versions')->max('id');
        if ($id) {
            DB::table('questionnaire_versions')->where('id', $id)->update(['is_active' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('questionnaire_versions', fn (Blueprint $t) => $t->dropColumn('is_active'));
    }
};
