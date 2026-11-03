<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The star: the log's one engagement verb.
 *
 * A commit's star count is a row count, not a denormalised column, because the
 * only thing that ever changes is "did this account star this commit" — and a
 * counter that drifts from its rows is the one bug a log cannot survive. The
 * unique index is the whole concurrency story: starring twice is the same row,
 * so a double click cannot inflate a count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_stars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['feed_post_id', 'user_id']);
            $table->index(['user_id', 'feed_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_stars');
    }
};
