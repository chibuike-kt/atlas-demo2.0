<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crypto_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('saved_contacts')->nullOnDelete();
            $table->string('token', 10);        // USDT, BTC, ETH
            $table->string('network', 20);      // bep20, trc20, erc20
            $table->string('wallet_label');     // Binance, Trust Wallet
            $table->decimal('balance', 24, 8)->default(0);
            $table->decimal('total_received', 24, 8)->default(0);
            $table->decimal('total_sent', 24, 8)->default(0);
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'contact_id', 'token', 'network']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crypto_balances');
    }
};
