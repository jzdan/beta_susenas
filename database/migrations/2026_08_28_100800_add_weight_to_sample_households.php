<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Increment 17 — penimbang (design weight) per rumah tangga sampel untuk estimasi tertimbang. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sample_households', function (Blueprint $t) {
            $t->decimal('weight', 12, 4)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('sample_households', fn (Blueprint $t) => $t->dropColumn('weight'));
    }
};
