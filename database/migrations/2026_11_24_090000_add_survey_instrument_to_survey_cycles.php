<?php

use App\Models\SurveyCycle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inc 154 — siklus dikelompokkan di halaman Periode Survei (tahun → triwulan → survei).
 * Dulu survei (SUSENAS/SERUTI) & instrumen (KOR/KP/INTI/…) hanya terbaca dari NAMA siklus;
 * sekarang jadi kolom sendiri supaya pengelompokan tidak bergantung pada cara menulis nama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_cycles', function (Blueprint $t) {
            $t->string('survey', 20)->default('SUSENAS');
            $t->string('instrument', 20)->nullable();
        });

        foreach (DB::table('survey_cycles')->get() as $c) {
            $qvCode = DB::table('questionnaire_versions')->where('cycle_id', $c->id)->orderByDesc('id')->value('code');
            [$survey, $instrument] = SurveyCycle::inferIdentity((string) $c->name, $qvCode);
            DB::table('survey_cycles')->where('id', $c->id)->update(['survey' => $survey, 'instrument' => $instrument]);
        }
    }

    public function down(): void
    {
        Schema::table('survey_cycles', function (Blueprint $t) {
            $t->dropColumn(['survey', 'instrument']);
        });
    }
};
