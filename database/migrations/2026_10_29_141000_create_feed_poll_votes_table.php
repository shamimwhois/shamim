<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A poll vote: one account, one choice, one poll.
 *
 * The corpus ships a poll with a tally baked into it — anonymous votes that
 * came with the content. Those have no voter, so they cannot be rows, and they
 * stay where they are: the poll's own JSON holds them as an immutable
 * **baseline**, and this table holds the votes cast on this install. The
 * number a reader sees is the two added together, which means no counter is
 * ever written and therefore no counter can drift from the rows behind it.
 *
 * The unique index on `feed_post_id` + `user_id` is the whole vote-once story:
 * a second click records nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_poll_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('option', 120);
            $table->timestamps();

            $table->unique(['feed_post_id', 'user_id']);
            $table->index(['feed_post_id', 'option']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_poll_votes');
    }
};
