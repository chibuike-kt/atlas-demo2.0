<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('rule_id');
            $table->tinyInteger('step_order')->comment('Execution order: 1, 2, 3...');
            $table->enum('action_type', [
                'send_bank',
                'save_piggyvest',
                'save_cowrywise',
                'convert_crypto',
                'pay_bill',
            ]);
            $table->enum('amount_type', ['fixed', 'percentage']);
            $table->decimal('amount', 20, 6)->comment('NGN amount or percentage 0-100');
            $table->json('config')->comment('Rail-specific config: contact_id, plan_id, biller, etc.');
            $table->string('label', 100)->nullable()->comment('e.g. Send to Mom');
            $table->timestamps();

            $table->unique(['rule_id', 'step_order']);
            $table->index('rule_id');

            $table->foreign('rule_id')
                ->references('id')
                ->on('rules')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_actions');
    }
};
