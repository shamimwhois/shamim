<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Refunds are rows, not a column, because partial refunds are a real
     * sequence: the order's status is an aggregate over these amounts, and a
     * single `refunded_cents` column could not carry a reason or an operator
     * per movement. `processed_by` is nulled rather than cascading so an
     * account deletion never erases the money trail.
     */
    public function up(): void
    {
        Schema::create('marketplace_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 16)->default('succeeded');
            $table->text('reason')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('marketplace_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_refunds');
    }
};
