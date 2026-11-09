<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The digest review queue: drawers session_digest proposed and held for the
 * owner instead of writing (MNEMON_DIGEST_REVIEW). A proposal is never
 * deleted: approving one writes the drawer and links it here, so the text as
 * the model proposed it stays beside what the owner filed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drawer_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('pending')->index();
            // Why it waited: below the confidence floor, or every proposal waits.
            $table->string('reason', 20);
            $table->string('wing_slug');
            $table->string('room_slug');
            $table->boolean('propose_new_room')->default(false);
            $table->text('content');
            $table->string('source');
            $table->json('metadata')->nullable();
            $table->float('confidence')->nullable();
            $table->string('session_id', 100)->nullable();
            $table->unsignedInteger('turn_start')->nullable();
            $table->unsignedInteger('turn_end')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->foreignId('drawer_id')->nullable()->constrained('drawers')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'turn_start', 'turn_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drawer_proposals');
    }
};
