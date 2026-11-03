<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A billing identity is kept apart from the account record for the same
     * reason money is kept in minor units: the two change on different
     * schedules and for different reasons. An account's name is what the site
     * calls you; a billing name is what an invoice must say to be usable as a
     * tax document, and pasting it onto `users` would mean every rename of the
     * display name silently rewrites who a buyer bills as.
     *
     * One profile per account. Nothing here is read when an invoice renders —
     * orders carry their own snapshot — so editing this row can never alter a
     * document that has already been issued.
     */
    public function up(): void
    {
        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('legal_name');
            $table->string('company')->nullable();
            $table->string('tax_id', 32)->nullable();
            $table->string('email');
            $table->string('phone', 32)->nullable();

            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city');
            $table->string('postal_code', 16)->nullable();
            $table->char('country', 2)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_profiles');
    }
};
