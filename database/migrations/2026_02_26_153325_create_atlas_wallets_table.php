<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('network', 20);        // bep20, trc20, erc20, polygon, solana
            $table->string('token', 10);          // USDT, BTC, ETH
            $table->string('address', 200);       // Atlas-issued wallet address
            $table->decimal('balance', 24, 8)->default(0);
            $table->decimal('total_deposited', 24, 8)->default(0);
            $table->decimal('total_withdrawn', 24, 8)->default(0);
            $table->decimal('total_converted', 24, 8)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'network', 'token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_wallets');
    }
};
