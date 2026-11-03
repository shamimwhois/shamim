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
        Schema::create('lab_snippets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * A stored string cast to a PHP enum, never a database enum, because the
             * default connection is SQLite. The column is the only place
             * `LabLanguage` is recorded, and the enum's `isBrowserRunnable()` is what
             * decides whether this row can ever be previewed.
             */
            $table->string('language', 32);

            $table->string('title');
            $table->string('slug')->unique();

            /*
             * The code itself. It is never markup: every surface that shows this
             * column escapes it into a `<pre><code>` block, so a snippet containing a
             * closing script tag is a string on the page rather than an instruction
             * to the browser reading it.
             */
            $table->longText('code');
            $table->text('description')->nullable();

            $table->string('visibility', 16)->default('private');
            $table->unsignedBigInteger('view_count')->default(0);
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'language']);
            $table->index(['visibility', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_snippets');
    }
};
