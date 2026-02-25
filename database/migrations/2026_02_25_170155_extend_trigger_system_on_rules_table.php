<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            // Extended trigger types
            $table->string('trigger_type', 30)->change(); // hourly, daily, weekly, monthly, deposit, balance, manual
            // Natural language — the original rule text the user typed
            $table->text('rule_text')->nullable()->after('description');
            // Parsed by Claude — full structured representation
            $table->json('parsed_rule')->nullable()->after('rule_text');
        });
    }

    public function down(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->dropColumn(['rule_text', 'parsed_rule']);
        });
    }
};
