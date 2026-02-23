<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('execution_id');
            $table->uuid('rule_action_id');
            $table->tinyInteger('step_order');
            $table->string('action_type', 50);
            $table->string('label', 100)->nullable();
            $table->decimal('amount_ngn', 20, 6)->comment('Resolved NGN amount for this step');
            $table->enum('status', [
                'pending',
                'running',
                'completed',
                'failed',
                'rolled_back',
            ])->default('pending');
            $table->string('rail_reference', 255)->nullable()->comment('External transaction ref');
            $table->json('result')->nullable()->comment('Full response from rail adapter');
            $table->json('rollback_payload')->nullable()->comment('Instructions to reverse this step');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('execution_id');
            $table->index('status');
            $table->index(['execution_id', 'step_order']);

            $table->foreign('execution_id')
                ->references('id')
                ->on('rule_executions')
                ->onDelete('cascade');

            $table->foreign('rule_action_id')
                ->references('id')
                ->on('rule_actions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_steps');
    }
};
