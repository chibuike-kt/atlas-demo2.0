<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('execution_id')->constrained('rule_executions')->cascadeOnDelete();
            $table->string('receipt_number', 30)->unique(); // ATL-RCP-2026-000001
            $table->decimal('total_amount', 20, 6);
            $table->decimal('total_fees', 20, 6)->default(0);
            $table->string('status', 20);
            $table->json('receipt_data');           // full snapshot for PDF generation
            $table->string('pdf_path')->nullable(); // storage path once generated
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
