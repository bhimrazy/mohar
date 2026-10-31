<?php

use App\Modules\Core\Support\LockTimeout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database review 2026-10-31, M5: the database refuses values the billing rules never allow.
 *
 * - tax_rules: a rate from 0 to 100 (TaxRuleRequest).
 * - invoice_items: a quantity of at least 1 (SaveDraftInvoiceRequest; discounts are negative unit amounts).
 * - invoices: a status of InvoiceStatus; a total below 0 only on a draft or a cancelled draft (PublishInvoice refuses
 *   it, and a reopened unnumbered invoice returns to draft); amount paid and credit applied never below 0 (a refund or
 *   chargeback is at most what the payment can still return, RemoveCredit at most the credit applied).
 * - transactions: a status of TransactionStatus; amount in, amount out and fee never below 0 (billing.md: the
 *   direction is the column, not the sign).
 *
 * A new enum case needs a migration that replaces its status check; BillingIntegrityConstraintsTest fails until then.
 *
 * PostgreSQL only. Each table's checks are added NOT VALID under one short lock, so new writes are checked at once;
 * then each is validated, which scans without blocking reads or writes. If existing rows break one, it is dropped
 * again and the migration stops naming them, rather than leaving a check that fails every later update of those rows.
 * SQLite cannot add a CHECK to an existing table without a rebuild, so there this migration does nothing.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, array<string, string>> table => constraint => condition */
    private const CHECKS = [
        'tax_rules' => [
            'tax_rules_rate_check' => 'rate >= 0 AND rate <= 100',
        ],
        'invoice_items' => [
            'invoice_items_quantity_check' => 'quantity > 0',
        ],
        'invoices' => [
            'invoices_status_check' => "status IN ('draft', 'unpaid', 'payment_pending', 'paid', 'cancelled', 'refunded', 'collections')",
            'invoices_money_sign_check' => "(total >= 0 OR status IN ('draft', 'cancelled')) AND amount_paid >= 0 AND credit_applied >= 0",
        ],
        'transactions' => [
            'transactions_status_check' => "status IN ('succeeded', 'pending', 'failed')",
            'transactions_money_sign_check' => 'amount_in >= 0 AND amount_out >= 0 AND fee >= 0',
        ],
    ];

    /** How many violating row ids the failure names. */
    private const NAMED_IDS = 20;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::CHECKS as $table => $checks) {
            $missing = array_diff_key($checks, $this->existing($table));

            if ($missing !== []) {
                // @compatible NOT VALID checks only new writes, and the previous release writes none of these values
                LockTimeout::run($table, fn () => DB::statement("ALTER TABLE {$table} ".implode(', ', array_map(
                    fn (string $name, string $condition): string => "ADD CONSTRAINT {$name} CHECK ({$condition}) NOT VALID",
                    array_keys($missing),
                    $missing,
                ))));
            }

            foreach ($checks as $name => $condition) {
                if ($this->existing($table)[$name] ?? false) {
                    continue;
                }

                $violating = DB::table($table)->whereRaw("NOT ({$condition})");
                $count = (clone $violating)->count();

                if ($count > 0) {
                    $ids = $violating->orderBy('id')->limit(self::NAMED_IDS)->pluck('id')->implode(', ');
                    // @compatible removes only the check this migration just added
                    LockTimeout::run($table, fn () => DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}"));

                    throw new RuntimeException("{$count} {$table} rows break {$name} ({$condition}), ids {$ids}. Correct them and run the migration again.");
                }

                LockTimeout::run($table, fn () => DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}"));
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::CHECKS as $table => $checks) {
            LockTimeout::run($table, fn () => DB::statement("ALTER TABLE {$table} ".implode(', ', array_map(
                fn (string $name): string => "DROP CONSTRAINT IF EXISTS {$name}",
                array_keys($checks),
            ))));
        }
    }

    /**
     * @return array<string, bool> constraint name => validated, for this migration's checks already on the table
     */
    private function existing(string $table): array
    {
        return DB::table('pg_constraint')->whereRaw('conrelid = to_regclass(?)', [$table])->where('contype', 'c')
            ->whereIn('conname', array_keys(self::CHECKS[$table]))->pluck('convalidated', 'conname')->map(fn ($valid): bool => (bool) $valid)->all();
    }
};
