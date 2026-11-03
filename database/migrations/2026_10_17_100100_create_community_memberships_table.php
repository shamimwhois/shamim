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
        Schema::create('community_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // owner | moderator | member
            $table->string('role', 16)->default('member');
            // Joined at is the membership's meaningful timestamp; updated_at has
            // no honest meaning for a row whose only mutable field is a role
            // the action classes never rewrite.
            $table->timestamp('joined_at')->useCurrent();

            // One membership per account per community — enforced by the
            // database, not only by the action class.
            $table->unique(['community_id', 'user_id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('community_memberships');
    }
};
