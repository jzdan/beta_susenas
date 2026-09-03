<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Increment 57 — kapan dokumen dikirim ke pengolahan (finalisasi).
 * Dipakai untuk umur antrean cleaning ("menunggu N hari") & urut tertua dulu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->timestamp('submitted_at')->nullable()->after('status');
        });

        // perkiraan awal untuk dokumen yang sudah lewat entri
        DB::table('documents')->whereIn('status', ['selesai', 'bersih', 'dikembalikan'])
            ->whereNull('submitted_at')
            ->update(['submitted_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn('submitted_at'));
    }
};
