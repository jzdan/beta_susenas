<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment 60 — pencatatan non-respons & penggantian sampel.
 * Alasan non-respons wajib dicatat; sampel pengganti menautkan ke sampel asal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sample_households', function (Blueprint $t) {
            $t->string('nonresponse_reason', 30)->nullable()->after('status');
            $t->string('status_note', 300)->nullable()->after('nonresponse_reason');
            $t->foreignId('replaces_id')->nullable()->after('status_note')
                ->constrained('sample_households')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sample_households', function (Blueprint $t) {
            $t->dropConstrainedForeignId('replaces_id');
            $t->dropColumn(['nonresponse_reason', 'status_note']);
        });
    }
};
