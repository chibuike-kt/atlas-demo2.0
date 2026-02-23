<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('execution_id')->nullable()->comment('Linked execution if engine-driven');
            $table->uuid('execution_step_id')->nullable()->comment('Linked step if engine-driven');
            $table->uuid('user_id');
            $table->enum('entry_type', ['debit', 'credit']);
            $table->decimal('amount', 20, 6)->comment('Always positive');
            $table->char('currency', 10)->comment('NGN, USDT, USD');
            $table->string('description', 255);
            $table->string('reference', 255)->nullable()->comment('Rail transaction reference');
            $table->decimal('balance_before', 20, 6);
            $table->decimal('balance_after', 20, 6);
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('execution_id');
            $table->index('currency');
            $table->index('created_at');

            $table->foreign('user_id')
                ->references('id')
                ->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
