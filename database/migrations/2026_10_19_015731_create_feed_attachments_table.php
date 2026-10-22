<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media attached to a community post.
 *
 * Deliberately its own table rather than a JSON column on feed_posts: the file
 * lives on a private disk and is only ever reachable through a short-lived
 * signed route, so it needs its own row to carry the disk, the server-read mime
 * type, the alt text and the ownership that makes the authorization check cheap.
 *
 * `feed_post_id` is nullable and `temp_key` is set while an upload belongs to no
 * post yet — an in-flight draft or an abandoned upload. `feed:prune-media` is
 * what reclaims those, so an abandoned composer never leaks a file on disk.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('feed_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feed_post_id')->nullable()->constrained()->cascadeOnDelete();
            // The uploader, not the author. Ownership of an unattached upload is
            // what stops one reader from previewing another's draft image.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('feed_media');
            $table->string('path');
            // Read back from the disk after the move, never taken from the request.
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->text('alt_text')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('temp_key')->nullable();
            $table->timestamps();

            $table->index(['feed_post_id', 'sort_order']);
            $table->index('temp_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feed_attachments');
    }
};
