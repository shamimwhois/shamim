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
        Schema::create('ai_article_topics', function (Blueprint $table) {
            $table->foreignId('ai_article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_topic_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['ai_article_id', 'ai_topic_id']);
            $table->index(['ai_topic_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_article_topics');
    }
};
