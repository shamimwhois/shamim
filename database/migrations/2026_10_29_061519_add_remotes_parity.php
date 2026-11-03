<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remotes, brought up to the reference's feature set.
 *
 * Three additions, all of them per-reader or per-push rather than per-thread:
 *
 * - `pinned_at` on a push. A pin is a property of the message, so anyone in the
 *   conversation sees the same pin and there is no join table to keep in step.
 * - `muted_at` and `archived_at` on a participant. Both are the reader's own
 *   choice about a conversation, which is why they live on the pivot and not on
 *   the remote: muting a remote for yourself must not silence it for the other
 *   person.
 * - Reactions. One row per person per emoji per push, so a toggle is a delete
 *   and the count is a group-by rather than a JSON column that has to be read
 *   and rewritten under a lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_messages', function (Blueprint $table): void {
            $table->timestamp('pinned_at')->nullable()->after('body');
            $table->index(['remote_id', 'pinned_at']);
        });

        Schema::table('remote_participants', function (Blueprint $table): void {
            $table->timestamp('muted_at')->nullable()->after('last_read_at');
            $table->timestamp('archived_at')->nullable()->after('muted_at');
        });

        Schema::create('remote_message_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('remote_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();

            // One person can hold one of each emoji on a push, and no more: the
            // toggle relies on this rather than on a read-then-write.
            $table->unique(['remote_message_id', 'user_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_message_reactions');

        Schema::table('remote_participants', function (Blueprint $table): void {
            $table->dropColumn(['muted_at', 'archived_at']);
        });

        Schema::table('remote_messages', function (Blueprint $table): void {
            $table->dropIndex(['remote_id', 'pinned_at']);
            $table->dropColumn('pinned_at');
        });
    }
};
