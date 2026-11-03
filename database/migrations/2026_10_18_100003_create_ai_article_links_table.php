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
        Schema::create('ai_article_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_article_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('url');
            $table->string('kind', 32)->default('reference');
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['ai_article_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_article_links');
    }
};
