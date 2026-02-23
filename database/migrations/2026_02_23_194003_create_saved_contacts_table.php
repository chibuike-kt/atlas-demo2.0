<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('label', 50)->comment('e.g. Mom, Wife, Landlord');
            $table->enum('type', ['bank', 'crypto'])->default('bank');

            // Bank fields — encrypted
            $table->text('account_name_enc')->nullable()->comment('AES-256-GCM encrypted');
            $table->text('account_number_enc')->nullable()->comment('AES-256-GCM encrypted');
            $table->string('bank_code', 10)->nullable()->comment('CBN bank code e.g. 058 for GTB');
            $table->string('bank_name', 100)->nullable();

            // Crypto fields — encrypted
            $table->text('wallet_address_enc')->nullable()->comment('AES-256-GCM encrypted');
            $table->string('crypto_network', 20)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('user_id');
            $table->index(['user_id', 'label']);

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_contacts');
    }
};
