<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Increment 25 — alur "kembalikan ke petugas" untuk perbaikan pengumpulan data. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->text('return_reason')->nullable()->after('status');
            $t->unsignedBigInteger('returned_by')->nullable()->after('return_reason');
            $t->timestamp('returned_at')->nullable()->after('returned_by');
        });
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn(['return_reason', 'returned_by', 'returned_at']));
    }
};
