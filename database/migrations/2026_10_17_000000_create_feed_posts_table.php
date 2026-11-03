<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('feed_posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            // Headline. The corpus predates it, so it is nullable for rows
            // seeded before the headline became part of the design.
            $table->string('title')->nullable();
            $table->string('author');
            $table->string('handle');
            // Author initials rendered in the avatar disc.
            $table->string('avatar', 8);
            // One-line author byline, e.g. "Staff Systems Engineer @ Cloudflare".
            $table->string('role')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->string('topic');
            // question | discussion | showcase | snippet | article | poll
            $table->string('type');
            $table->boolean('answered')->default(false);
            $table->unsignedInteger('score')->default(0);
            $table->unsignedInteger('reports')->default(0);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('comments')->default(0);
            $table->text('body');
            // array<int, string>
            $table->json('tags');
            // array{like: int, love: int, insight: int}
            $table->json('reactions');
            // Moderation state — columns, not a session map, so every surface
            // (public feed, detail, admin console) reads one source of truth.
            $table->boolean('pinned')->default(false);
            $table->boolean('featured')->default(false);
            $table->boolean('hidden')->default(false);
            $table->boolean('deleted')->default(false);
            $table->text('code')->nullable();
            $table->string('code_file')->nullable();
            $table->unsignedTinyInteger('read_minutes')->nullable();
            // array{options: array<int, array{label: string, votes: int}>, votes: int, closes: string}
            $table->json('poll')->nullable();
            // array{author: string, avatar: string, body: string}
            $table->json('answer')->nullable();
            // array{label: string, detail: string}
            $table->json('activity')->nullable();
            // array{title: string, code: string, diff: array{filename: string, summary: string, lines: array<int, mixed>}, replies: array<int, mixed>}
            $table->json('detail')->nullable();
            $table->timestamps();

            $table->index(['hidden', 'deleted']);
            $table->index('topic');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feed_posts');
    }
};
