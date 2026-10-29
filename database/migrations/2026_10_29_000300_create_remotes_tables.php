<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remotes: the log's direct messages.
 *
 * A remote is a conversation, direct (two people) or a group; the participants
 * table is what makes both the same shape, so the page never branches on kind
 * to decide who may read it. `last_read_at` per participant is the unread
 * marker — a push is read when the reader's marker passes it, which is also
 * where the "HEAD" position in the thread comes from.
 *
 * The vocabulary is the design's: a conversation is a remote, sending is a
 * push, reading is a pull. No branches, no diffs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remotes', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16)->default('direct');
            $table->string('title')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('remote_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('remote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['remote_id', 'user_id']);
            $table->index(['user_id']);
        });

        Schema::create('remote_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('remote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['remote_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_messages');
        Schema::dropIfExists('remote_participants');
        Schema::dropIfExists('remotes');
    }
};
