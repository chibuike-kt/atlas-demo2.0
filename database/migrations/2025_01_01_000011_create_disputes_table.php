<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('disputes', function (Blueprint $table) {
      $table->uuid('id')->primary();
      $table->uuid('user_id');
      $table->uuid('execution_id');
      $table->string('dispute_number', 30)->unique();
      $table->string('reason', 50);
      $table->text('description');
      $table->decimal('amount_ngn', 18, 2);
      $table->decimal('refund_amount', 18, 2)->nullable();
      $table->enum('status', [
        'open',
        'under_review',
        'resolved_refund',
        'resolved_no_action',
        'closed',
      ])->default('open');
      $table->text('resolution_note')->nullable();
      $table->timestamp('opened_at')->nullable();
      $table->timestamp('resolved_at')->nullable();
      $table->timestamps();

      $table->foreign('user_id')->references('id')->on('users');
      $table->foreign('execution_id')->references('id')->on('rule_executions');
      $table->index(['user_id', 'status']);
      $table->index('execution_id');
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('disputes');
  }
};
