<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remotes, second pass: quoting, pinning, timed mutes and pull receipts.
 *
 * - `reply_to_id` on a push. A quote points at the push it answers, so the
 *   thread can print the line being answered without copying its text into the
 *   new row — an edited or deleted original stays honest.
 * - `pinned_at` on a participant. Pinning a *conversation* is the reader's own
 *   ordering, which is why it sits on the pivot next to `archived_at` and not
 *   on the remote.
 * - `muted_until_at` replaces `muted_at`. A mute in this design is a duration,
 *   not a flag: "for one hour" has to end on its own, and "forever" is the far
 *   end of the calendar rather than a boolean the reader has to remember to
 *   turn off. Existing mutes are carried over as forever.
 * - `remote_pull_receipts` on a user. When off, nobody sees when they pull and
 *   they see nobody's pulls either, so the receipt is a two-sided preference
 *   and cannot live on a single conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_messages', function (Blueprint $table): void {
            $table->foreignId('reply_to_id')
                ->nullable()
                ->after('body')
                ->constrained('remote_messages')
                ->nullOnDelete();
        });

        Schema::table('remote_participants', function (Blueprint $table): void {
            $table->timestamp('muted_until_at')->nullable()->after('last_read_at');
            $table->timestamp('pinned_at')->nullable()->after('muted_until_at');
        });

        // A mute used to be a flag, so every row that has one becomes a mute
        // that never expires rather than being lost in the rename.
        DB::table('remote_participants')
            ->whereNotNull('muted_at')
            ->update(['muted_until_at' => '9999-12-31 00:00:00']);

        Schema::table('remote_participants', function (Blueprint $table): void {
            $table->dropColumn('muted_at');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('remote_pull_receipts')->default(true)->after('handle');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('remote_pull_receipts');
        });

        Schema::table('remote_participants', function (Blueprint $table): void {
            $table->timestamp('muted_at')->nullable()->after('last_read_at');
        });

        DB::table('remote_participants')
            ->whereNotNull('muted_until_at')
            ->update(['muted_at' => '9999-12-31 00:00:00']);

        Schema::table('remote_participants', function (Blueprint $table): void {
            $table->dropColumn(['muted_until_at', 'pinned_at']);
        });

        Schema::table('remote_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reply_to_id');
        });
    }
};
