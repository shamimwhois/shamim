<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The inbox.
 *
 * One row per thing that happened to you, with the actor and the commit it
 * happened to. `type` is a string rather than an enum column so a new kind of
 * event is a code change, not a migration — the page filters on the same
 * vocabulary the writers use.
 *
 * `read_at` is the whole read model: the inbox marks everything it showed as
 * read when it opens, and the "new since your last visit" ring is decided by
 * comparing that timestamp with the visit's start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 24);
            $table->foreignId('feed_post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('feed_reply_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_notifications');
    }
};
