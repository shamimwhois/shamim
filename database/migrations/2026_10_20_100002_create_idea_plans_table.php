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
        Schema::create('idea_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * The idea a plan came from, kept as a link rather than a lineage. It
             * nulls on delete: an account that cleans out its inbox must not be able
             * to damage the plan the inbox entry produced, and `docs/Idea.md` is
             * explicit that converting an idea preserves the original.
             *
             * The index is unique, so "one idea, one plan" is a fact of the schema
             * rather than a promise made by the conversion action. Many nulls are
             * allowed, which is exactly right: a plan written from scratch, and a
             * plan whose idea has since been deleted, both sit outside the rule.
             */
            $table->foreignId('idea_id')->nullable()->unique()
                ->constrained('ideas')
                ->nullOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->text('problem_statement')->nullable();
            $table->text('proposed_solution')->nullable();
            $table->text('scope')->nullable();
            $table->text('out_of_scope')->nullable();
            $table->text('goals')->nullable();

            /*
             * Requirements, technical approach and stack are written into one
             * Markdown body rather than three more nullable columns. The spec asks
             * for sections "filled in gradually", which is a form concern: a schema
             * that pre-splits the prose would make every later change to the form a
             * migration.
             */
            $table->longText('notes')->nullable();

            $table->string('status', 32)->default('draft');
            $table->string('priority', 16)->nullable();
            $table->string('estimated_effort', 60)->nullable();
            $table->date('target_start_date')->nullable();
            $table->date('target_end_date')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idea_plans');
    }
};
