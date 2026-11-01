<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One attempt against one instrument, kept apart from the order so the
     * gateway's notion of a transaction and the platform's notion of a sale
     * stay separate records. A `reference` is the provider's transaction id —
     * in the sandbox, the demo harness's.
     */
    public function up(): void
    {
        Schema::create('marketplace_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_order_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('mode', 8)->default('test');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 16)->default('pending');
            $table->string('reference')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['marketplace_order_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_payments');
    }
};
