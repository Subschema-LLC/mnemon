<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded documents: the original file, kept privately on disk, and the
 * drawers its text was split into.
 *
 * The palace still holds the searchable content. A document only records
 * where its drawers came from, so the original can be downloaded, its text
 * extracted again, or the whole upload removed at once. The room link is
 * nullOnDelete rather than cascade: a database cascade fires no model events,
 * so the file on disk would be left behind with no row pointing at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_name');
            // docx, pptx, pdf, csv, png, jpg, webp or svg: decided by content, not by the name.
            $table->string('type', 16);
            $table->string('mime', 127);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64)->index();
            $table->string('disk', 32);
            $table->string('path');
            // processing, processed, no_text or failed.
            $table->string('status', 20)->default('processing')->index();
            $table->text('error')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->text('note')->nullable();
            $table->string('source');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['room_id', 'sha256']);
        });

        Schema::table('drawers', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->after('room_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('drawers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
        });

        Schema::dropIfExists('documents');
    }
};
