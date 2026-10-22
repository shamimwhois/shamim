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
        Schema::create('idea_topic', function (Blueprint $table) {
            $table->foreignId('idea_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();

            /*
             * The author's own order, written by SyncIdeaTopics. It is not cosmetic:
             * the first row is the primary topic, and it is what a card badge and the
             * structured-data keywords name when an idea carries more than one.
             */
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['idea_id', 'topic_id']);
            $table->index(['topic_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idea_topic');
    }
};
