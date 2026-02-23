<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('connected_account_id')->comment('Source account to debit');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->enum('trigger_type', [
                'schedule',
                'deposit',
                'balance',
                'manual',
            ]);
            $table->json('trigger_config')->comment('e.g. {"day":30} or {"min_amount":500000}');
            $table->enum('total_amount_type', [
                'fixed',
                'percentage',
                'full_balance',
            ])->default('fixed');
            $table->decimal('total_amount', 20, 6)->nullable()->comment('Fixed NGN amount — null if full_balance');
            $table->char('currency', 3)->default('NGN');
            $table->enum('on_failure', ['rollback', 'continue', 'stop'])->default('rollback');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->unsignedInteger('execution_count')->default(0);
            $table->timestamps();

            $table->index('user_id');
            $table->index(['trigger_type', 'is_active']);
            $table->index('connected_account_id');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('connected_account_id')
                ->references('id')
                ->on('connected_accounts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rules');
    }
};
