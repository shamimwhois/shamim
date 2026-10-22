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
        Schema::create('lab_snippet_topic', function (Blueprint $table) {
            $table->foreignId('lab_snippet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();

            /*
             * The same author-chosen order as the ideas pivot, so a snippet card can
             * name one subject rather than the first row a query happened to return.
             */
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['lab_snippet_id', 'topic_id']);
            $table->index(['topic_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_snippet_topic');
    }
};
