<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_contacts', function (Blueprint $table) {
            // Crypto network — bep20, trc20, erc20, polygon, solana, arbitrum
            $table->string('crypto_network', 30)->nullable()->change();
            $table->string('wallet_label')->nullable()->after('crypto_network'); // e.g. "Binance", "Trust Wallet"
        });
    }

    public function down(): void
    {
        Schema::table('saved_contacts', function (Blueprint $table) {
            $table->dropColumn('wallet_label');
        });
    }
};
