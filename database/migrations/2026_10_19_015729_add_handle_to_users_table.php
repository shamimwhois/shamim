<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The handle is the identity the community already sees on every feed card —
 * `@aisha`, `@danielo` — so the account carries it too. Stored bare, without
 * the `@`, because the decoration belongs to the surface that renders it, not
 * to the data.
 *
 * Nullable and unique: the three starter accounts and every registered reader
 * arrive without one, and only the seeded personas claim a handle.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('handle')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['handle']);
            $table->dropColumn('handle');
        });
    }
};
