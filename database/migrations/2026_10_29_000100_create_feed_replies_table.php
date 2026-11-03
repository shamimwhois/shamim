<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replies: the log's conversation.
 *
 * Kept apart from `comments`, which belongs to blog posts and is bound to
 * `posts.id`. A commit's thread is flat — the design draws one level of
 * indentation and stops — so the table carries no `parent_id`: a reply to a
 * reply is still a reply to the commit, and the thread reads as one
 * conversation rather than a tree nobody asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['feed_post_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_replies');
    }
};
