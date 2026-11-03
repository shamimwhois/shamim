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
        Schema::create('lab_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * The language that was run, recorded rather than derived. A history row
             * that answers "what did I run" by looking the source up again would go
             * blank when the source is renamed or deleted, and a history whose shape
             * depends on somebody else's housekeeping is not a history.
             */
            $table->string('language', 32);

            /*
             * The workspace name or snippet title at the moment of the run.
             * Denormalised on purpose, for the same reason as the column above: the
             * sentence has to survive the deletion of the thing it describes.
             */
            $table->string('label', 160)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_executions');
    }
};
