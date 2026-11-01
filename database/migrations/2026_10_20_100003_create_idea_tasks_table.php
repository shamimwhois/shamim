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
        Schema::create('idea_tasks', function (Blueprint $table) {
            $table->id();

            /*
             * Not nullable, and cascading. A task with no plan is a fact about
             * nothing, and ownership of a task derives from its plan's author, so
             * there is no `user_id` column here that could drift away from the plan
             * it belongs to.
             */
            $table->foreignId('plan_id')->constrained('idea_plans')->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('todo');
            $table->string('priority', 16)->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['plan_id', 'status']);
            $table->index(['plan_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idea_tasks');
    }
};
