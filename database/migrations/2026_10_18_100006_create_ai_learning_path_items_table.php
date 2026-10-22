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
        Schema::create('ai_learning_path_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_learning_path_id')->constrained('ai_learning_paths')->cascadeOnDelete();
            $table->foreignId('ai_article_id')->constrained('ai_articles')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['ai_learning_path_id', 'ai_article_id']);
            $table->index(['ai_learning_path_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_learning_path_items');
    }
};
