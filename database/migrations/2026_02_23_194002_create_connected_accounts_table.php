<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('mono_account_id', 100)->comment('Mono account ID from Connect widget');
            $table->string('institution_name', 100);
            $table->string('institution_code', 20);
            $table->string('account_name', 150);
            $table->text('account_number_enc')->comment('AES-256-GCM encrypted');
            $table->string('account_type', 50)->comment('SAVINGS or CURRENT');
            $table->char('currency', 3)->default('NGN');
            $table->decimal('balance', 20, 6)->default(0);
            $table->timestamp('balance_synced_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique('mono_account_id');
            $table->index('user_id');
            $table->index(['user_id', 'is_primary']);

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_accounts');
    }
};
