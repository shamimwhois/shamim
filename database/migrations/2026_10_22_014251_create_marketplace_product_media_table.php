<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screenshots attached to a marketplace listing.
 *
 * Its own table rather than a JSON column on marketplace_products for the same
 * reason feed attachments have one: the bytes live on a private disk and are only
 * ever reachable through a short-lived signed route, so each file needs its own
 * row to carry the disk, the mime read back from storage, the alt text and the
 * order the seller chose.
 *
 * There is no staging column here. A screenshot belongs to a listing that already
 * exists — the editor only offers the uploader once the draft has an id — so every
 * row has a product from the moment it is written, and nothing can orphan a file.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('marketplace_product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_product_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('marketplace_media');
            $table->string('path');
            // Read back from the disk after the move, never taken from the request.
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->text('alt_text')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // Named explicitly: the generated name would exceed MySQL's
            // 64-character identifier limit.
            $table->index(['marketplace_product_id', 'sort_order'], 'mpm_product_sort_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_product_media');
    }
};
