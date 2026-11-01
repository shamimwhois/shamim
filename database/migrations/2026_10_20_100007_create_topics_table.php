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
        Schema::create('topics', function (Blueprint $table) {
            $table->id();

            /*
             * The platform's public subject list, shared by Ideas and the Developer
             * Lab. It is deliberately not `idea_categories`, which is one account's
             * private filing and must stay invisible to strangers, and it is not
             * `ai_topics`, which is the hub's own one-level tree. A row here is
             * nothing until a published idea or snippet carries it, and the public
             * facet row hides any topic whose published count is zero.
             */
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon', 64)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('topics');
    }
};
