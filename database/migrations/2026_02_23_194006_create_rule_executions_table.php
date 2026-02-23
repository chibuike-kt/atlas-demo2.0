<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('rule_id');
            $table->uuid('user_id');
            $table->enum('triggered_by', ['schedule', 'deposit', 'balance', 'manual']);
            $table->json('rule_snapshot')->comment('Full copy of rule+actions at time of execution');
            $table->decimal('total_amount_ngn', 20, 6)->comment('Total NGN debited');
            $table->enum('status', [
                'pending',
                'running',
                'completed',
                'failed',
                'rolled_back',
            ])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('rule_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');

            $table->foreign('rule_id')
                ->references('id')
                ->on('rules');

            $table->foreign('user_id')
                ->references('id')
                ->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_executions');
    }
};
