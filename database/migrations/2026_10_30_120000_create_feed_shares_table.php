<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The share: a record that an account passed a commit on.
 *
 * Same doctrine as `feed_stars` and `feed_poll_votes` — the count is a row
 * count, never a column, because the only thing that changes is "did this
 * account already share this" and a counter that drifts from its rows is a bug
 * nobody can see until it is wrong.
 *
 * `channel` is what makes the ledger worth keeping rather than a badge: it
 * separates a link copied to the clipboard from a post handed to the OS share
 * sheet, so the two can be reported on without a second table. The unique index
 * is per (post, user) rather than per (post, user, channel) on purpose — sharing
 * the same commit by two routes is still one account amplifying it once, and
 * letting it count twice would make the number mean nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32)->default('link');
            $table->timestamps();

            $table->unique(['feed_post_id', 'user_id']);
            $table->index(['user_id', 'feed_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_shares');
    }
};
