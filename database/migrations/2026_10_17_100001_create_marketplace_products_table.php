<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Money is stored as an integer number of minor units plus an ISO-4217
     * currency code. A float column would make `19.99` unrepresentable and a
     * single-currency column would bake the storefront's first market into the
     * schema — both are decisions that are expensive to undo once orders exist.
     */
    public function up(): void
    {
        Schema::create('marketplace_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('tagline', 200);
            $table->longText('description');

            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('license', 32)->default('mit');

            $table->string('version', 32)->default('1.0.0');
            $table->string('framework', 64)->nullable();
            $table->string('language', 64)->nullable();
            $table->string('install_command')->nullable();

            $table->string('demo_url')->nullable();
            $table->string('docs_url')->nullable();
            $table->string('repository_url')->nullable();

            $table->json('tags')->nullable();

            $table->string('status', 20)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->unsignedBigInteger('download_count')->default(0);

            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_released_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['marketplace_category_id', 'status']);
            $table->index(['status', 'is_featured']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_products');
    }
};
