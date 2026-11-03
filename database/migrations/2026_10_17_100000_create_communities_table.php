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
        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            // One paragraph of directory copy; shown on cards and the community page.
            $table->string('description', 280);
            // The feed topic this community maps onto. Every feed post with the
            // same topic belongs here — one association, no second join table.
            $table->string('topic')->index();
            // Author initials rendered in the avatar disc.
            $table->string('glyph', 4);
            // open — anyone may join and read; invite-only — members-only content.
            $table->string('kind', 16)->default('open')->index();
            // House rules, rendered verbatim on the community page. Nullable:
            // open communities may run without them.
            $table->text('rules')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communities');
    }
};
