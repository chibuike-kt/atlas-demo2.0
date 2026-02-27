<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('execution_id', 36)->nullable();
            $table->string('execution_step_id', 36)->nullable();
            $table->string('fee_type', 30);
            $table->decimal('transaction_amount', 20, 6)->default(0);
            $table->decimal('fee_amount', 20, 6)->default(0);
            $table->decimal('fee_rate', 10, 6)->default(0);
            $table->string('currency', 10)->default('NGN');
            $table->string('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_ledger');
    }
};
