<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    // ── rule_executions ───────────────────────────────────────────────────
    // IMPORTANT: Schema::hasColumn() must be called OUTSIDE Schema::table()
    // closures — calling it inside causes "too few arguments" on Windows/older Laravel.
    $addServiceCharge = !Schema::hasColumn('rule_executions', 'service_charge_ngn');
    $addTotalDebit    = !Schema::hasColumn('rule_executions', 'total_debit_ngn');

    if ($addServiceCharge || $addTotalDebit) {
      Schema::table(
        'rule_executions',
        function (Blueprint $t) use ($addServiceCharge, $addTotalDebit) {
          if ($addServiceCharge) {
            $t->decimal('service_charge_ngn', 18, 6)
              ->default(0)
              ->after('total_amount_ngn');
          }
          if ($addTotalDebit) {
            $t->decimal('total_debit_ngn', 18, 6)
              ->default(0)
              ->after('service_charge_ngn');
          }
        }
      );
    }

    // ── ledger_entries ────────────────────────────────────────────────────
    $addLedgerCharge  = !Schema::hasColumn('ledger_entries', 'service_charge');
    $addAmountNgn     = !Schema::hasColumn('ledger_entries', 'amount_ngn');
    $addCryptoNetwork = !Schema::hasColumn('ledger_entries', 'crypto_network');

    if ($addLedgerCharge || $addAmountNgn || $addCryptoNetwork) {
      Schema::table(
        'ledger_entries',
        function (Blueprint $t) use ($addLedgerCharge, $addAmountNgn, $addCryptoNetwork) {
          if ($addLedgerCharge) {
            $t->decimal('service_charge', 18, 6)->default(0)->after('amount');
          }
          if ($addAmountNgn) {
            $t->decimal('amount_ngn', 18, 6)->nullable()->after('service_charge');
          }
          if ($addCryptoNetwork) {
            $t->string('crypto_network', 20)->nullable()->after('amount_ngn');
          }
        }
      );
    }

    // ── Backfill existing rule_executions ─────────────────────────────────
    // Old rows had no service charge — set total_debit = total_amount so the
    // column is consistent. DB facade is imported at top of file.
    DB::table('rule_executions')
      ->where('total_debit_ngn', 0)
      ->update([
        'service_charge_ngn' => 0,
        'total_debit_ngn'    => DB::raw('total_amount_ngn'),
      ]);
  }

  public function down(): void
  {
    if (Schema::hasColumn('rule_executions', 'service_charge_ngn')) {
      Schema::table('rule_executions', function (Blueprint $t) {
        $t->dropColumn('service_charge_ngn');
      });
    }

    if (Schema::hasColumn('rule_executions', 'total_debit_ngn')) {
      Schema::table('rule_executions', function (Blueprint $t) {
        $t->dropColumn('total_debit_ngn');
      });
    }

    if (Schema::hasColumn('ledger_entries', 'service_charge')) {
      Schema::table('ledger_entries', function (Blueprint $t) {
        $t->dropColumn('service_charge');
      });
    }

    if (Schema::hasColumn('ledger_entries', 'amount_ngn')) {
      Schema::table('ledger_entries', function (Blueprint $t) {
        $t->dropColumn('amount_ngn');
      });
    }

    if (Schema::hasColumn('ledger_entries', 'crypto_network')) {
      Schema::table('ledger_entries', function (Blueprint $t) {
        $t->dropColumn('crypto_network');
      });
    }
  }
};
