<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The same argument that made `product_name` and `price_cents` snapshots on
     * this table applies to the buyer's side of the receipt: an invoice is a
     * document, and a document that re-renders differently after the buyer
     * edits their billing profile is not one. So the billing line is copied at
     * checkout and never re-read.
     *
     * Every column is nullable because orders already exist and because the
     * checkout must not fail for a buyer who never filled a billing profile —
     * in that case the account's own name and email are the snapshot, and the
     * address columns stay empty.
     */
    public function up(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->string('billing_name')->nullable()->after('currency');
            $table->string('billing_company')->nullable()->after('billing_name');
            $table->string('billing_tax_id', 32)->nullable()->after('billing_company');
            $table->string('billing_email')->nullable()->after('billing_tax_id');
            $table->string('billing_address')->nullable()->after('billing_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->dropColumn([
                'billing_name',
                'billing_company',
                'billing_tax_id',
                'billing_email',
                'billing_address',
            ]);
        });
    }
};
