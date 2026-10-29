<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every release is a row, not an overwritten column, because a buyer who
     * paid for a version must be able to read what changed in it after the
     * product has moved on. `is_current` is a flag rather than a max() lookup
     * so the product page can show the current release without a correlated
     * subquery per card.
     */
    public function up(): void
    {
        Schema::create('marketplace_product_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_product_id')->constrained()->cascadeOnDelete();
            $table->string('version', 32);
            $table->text('release_notes')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamp('released_at');
            $table->timestamps();

            // Index names are given explicitly: the generated names would exceed
            // MySQL's 64-character identifier limit.
            $table->unique(['marketplace_product_id', 'version'], 'mpv_product_version_unique');
            $table->index(['marketplace_product_id', 'released_at'], 'mpv_product_released_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_product_versions');
    }
};
