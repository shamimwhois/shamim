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
        Schema::create('ai_article_related', function (Blueprint $table) {
            $table->foreignId('ai_article_id')->constrained('ai_articles')->cascadeOnDelete();
            $table->foreignId('related_article_id')->constrained('ai_articles')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['ai_article_id', 'related_article_id']);
            $table->index(['related_article_id', 'ai_article_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_article_related');
    }
};
