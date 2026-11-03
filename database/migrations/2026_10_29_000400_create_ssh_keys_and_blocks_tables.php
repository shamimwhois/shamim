<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two settings that need their own rows: keys and blocks.
 *
 * A key is a name and a fingerprint — the public half only. It exists so a
 * reader can see what can push to their account and revoke it, which is the
 * whole point of the terminal settings screen.
 *
 * A block is one direction of a pair; muting is the same row with `muted` set,
 * because both mean "this account does not reach me" and splitting them into
 * two tables would let a person be both by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('fingerprint', 64);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'fingerprint']);
        });

        Schema::create('user_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('muted')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'blocked_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_blocks');
        Schema::dropIfExists('ssh_keys');
    }
};
