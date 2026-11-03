<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publishing state for the community feed.
 *
 * Every column here is nullable or defaulted to the value the existing curated
 * corpus already behaves like, so the migration turns ten read-only rows into
 * ten published, public, community-owned posts without touching their content.
 *
 * `user_id` is the ownership and authorization key only. `author`, `handle` and
 * `avatar` stay the rendered truth on purpose: the feed is one query per render,
 * and a card that eager-loaded a user to print a name would break that.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('feed_posts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            // draft | scheduled | published — see App\Enums\FeedPostStatus.
            $table->string('status')->default('published')->after('type');
            $table->timestamp('scheduled_at')->nullable()->after('status');
            // public | self | community — see App\Enums\FeedPostAudience.
            $table->string('audience')->default('public')->after('scheduled_at');
            $table->foreignId('community_id')->nullable()->after('audience')->constrained()->nullOnDelete();

            $table->index(['status', 'posted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('feed_posts', function (Blueprint $table) {
            $table->dropIndex(['status', 'posted_at']);
            $table->dropConstrainedForeignId('community_id');
            $table->dropColumn(['audience', 'scheduled_at', 'status']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
