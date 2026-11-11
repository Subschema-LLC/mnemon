<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a recorded model call was for: a session digest, or a vision caption
 * for an uploaded image. Both are paid for and both count towards spend, but
 * only digests describe what a backfill will cost, so the per-call averages
 * the admin panel shows for calibrating that estimate count digests alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digest_usages', function (Blueprint $table) {
            $table->string('purpose', 20)->default('digest')->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('digest_usages', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
