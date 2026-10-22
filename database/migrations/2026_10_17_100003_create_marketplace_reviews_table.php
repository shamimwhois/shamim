<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One review per account per product: the unique index is what makes the
     * rating meaningful, because five reviews from one account would otherwise
     * outweigh five from five people. Rating is a small unsigned integer
     * constrained in the application layer to 1–5.
     */
    public function up(): void
    {
        Schema::create('marketplace_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 120)->nullable();
            $table->text('body');
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->unique(['marketplace_product_id', 'user_id']);
            $table->index(['status', 'created_at']);
            $table->index(['marketplace_product_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_reviews');
    }
};
