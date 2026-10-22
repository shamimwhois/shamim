<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The order is a receipt, not a view: name, slug, price and currency are
     * snapshotted at purchase so the console still reads correctly after the
     * listing is renamed, repriced, soft-deleted, or force-deleted. The
     * product foreign key is nullable with `nullOnDelete` for exactly that
     * reason — sales history outlives the listing.
     *
     * Entitlement is derived from this table (a Paid or PartiallyRefunded
     * order for buyer + product), so there is no separate grants table to
     * fall out of sync with the money.
     */
    public function up(): void
    {
        Schema::create('marketplace_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 32)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_name');
            $table->string('product_slug');
            $table->unsignedInteger('price_cents');
            $table->string('currency', 3);
            $table->unsignedInteger('commission_cents')->default(0);

            $table->string('status', 32)->default('pending');
            $table->string('payment_provider', 32)->nullable();
            $table->string('payment_mode', 8)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index('status');
            $table->index('marketplace_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_orders');
    }
};
