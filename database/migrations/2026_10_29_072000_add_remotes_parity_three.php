<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remotes, third pass: the two switches that decide what the inbox is.
 *
 * The reference keeps these on the profile, because they are account-wide
 * answers rather than per-conversation ones:
 *
 * - `remote_requests` — whether a first push from someone you have never
 *   answered lands in Fetched or straight in Open. It is a screening choice, so
 *   turning it off has to let the next stranger through, not retroactively move
 *   the ones already fetched.
 * - `remotes_enabled` — whether remotes exist for this account at all. A person
 *   who turns them off keeps the history; they just stop being a place anyone
 *   can push to.
 *
 * Blocking needs no column here: `user_blocks` already holds one row per
 * direction of a pair, and a block is that row with `muted` false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('remote_requests')->default(true)->after('remote_pull_receipts');
            $table->boolean('remotes_enabled')->default(true)->after('remote_requests');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['remote_requests', 'remotes_enabled']);
        });
    }
};
