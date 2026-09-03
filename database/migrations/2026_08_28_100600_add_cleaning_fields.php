<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Increment 8 — modul pengolahan (cleaning). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->foreignId('cleaned_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $t->timestamp('cleaned_at')->nullable()->after('cleaned_by');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cleaned_by');
            $t->dropColumn('cleaned_at');
        });
    }
};
