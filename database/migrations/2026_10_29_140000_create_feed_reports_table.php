<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A report: one account saying "this commit should not be here".
 *
 * The same shape as the star for the same reason: a report is a row, not a
 * counter, so the count on the post and the rows that back it cannot drift.
 * The unique index is the whole concurrency story — reporting twice is the
 * same row, so a double click cannot inflate a count.
 *
 * The reason is kept, not just the fact: a moderator looking at a queue of
 * reports needs to know whether it is spam or harassment, and "reported" on
 * its own is not a triage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 20);
            $table->timestamps();

            $table->unique(['feed_post_id', 'user_id']);
            $table->index(['user_id', 'feed_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_reports');
    }
};
