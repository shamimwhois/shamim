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
        Schema::create('lab_workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * A workspace is the unsaved-forever buffer: whatever is in the three
             * panes right now, under a name the author chose so it can be found
             * again. One row is exactly the trio a live preview needs, so there are
             * three columns rather than a JSON blob. Nothing queries inside a pane,
             * and a cast maintained for nobody is a cast maintained wrong.
             */
            $table->string('name');
            $table->longText('html')->nullable();
            $table->longText('css')->nullable();
            $table->longText('js')->nullable();

            /*
             * Set by the Run button, not by Save. The dashboard sorts by
             * `updated_at` and shows this so "saved an hour ago, run five minutes
             * ago" stays readable as two different facts.
             */
            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_workspaces');
    }
};
