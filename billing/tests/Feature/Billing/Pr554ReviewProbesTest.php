<?php

/*
| Review board probes for PR #554 (review/pr-554-probes). Each one is red at 68ee93bc.
*/

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

test('a check that existing rows break leaves no other check of that table behind NOT VALID', function () {
    $migration = require base_path('app/Modules/Billing/Database/Migrations/2026_11_04_300830_add_check_constraints_to_billing_tables.php');
    $migration->down();
    $invoice = issueInvoice(billingClient());
    // One historical row breaks the status check (validated first) and the money check (validated second).
    DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'Paid', 'amount_paid' => -1]);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'invoices_status_check');

    // The migration says it stops "rather than leaving a check that fails every later update of those rows", but the
    // money check added NOT VALID in the same ALTER is left on the table: every UPDATE of that invoice now fails.
    expect(DB::table('pg_constraint')->whereRaw("conrelid = 'invoices'::regclass")->whereIn('conname', ['invoices_status_check', 'invoices_money_sign_check'])->pluck('conname')->all())->toBe([]);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'The checks are PostgreSQL only');
