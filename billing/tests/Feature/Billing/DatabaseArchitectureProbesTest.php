<?php

/*
| Probes from the whole-database architecture review of 2026-10-31 (docs/reviews/2026-10-31-database-architecture.md).
| Each one states the safe behaviour and is red on develop; the fix PR named in the review adopts it. A refused
| statement runs in a savepoint, so the PostgreSQL test transaction stays usable for the check after it.
*/

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

it('refuses to delete a client that still has a credit ledger (finding H1: credit_ledger cascades on client delete)', function (): void {
    $client = billingClient();
    DB::table('credit_ledger')->insert([
        'client_id' => $client->id, 'currency' => $client->currency, 'amount' => 5_000, 'balance_after' => 5_000,
        'reason' => 'manual_add', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // A money ledger is an audit record: the row that owns it must not take it along. Today the foreign key is
    // ON DELETE CASCADE, so the delete succeeds and the 5,000 of credit history disappears without a trace.
    expect(fn () => DB::transaction(fn () => DB::table('clients')->where('id', $client->id)->delete()))->toThrow(QueryException::class);
    expect(DB::table('credit_ledger')->where('client_id', $client->id)->count())->toBe(1);
});

it('refuses to delete a tax rule an invoice was calculated with (finding M3: tax1_rule_id has no foreign key)', function (): void {
    $invoice = issueInvoice(billingClient(), [10_000]);
    $ruleId = DB::table('tax_rules')->insertGetId([
        'name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000', 'priority' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('invoices')->where('id', $invoice->id)->update(['tax1_rule_id' => $ruleId]);

    // The rule id is the input the Nepal VAT classification reads (ADR 0024 Amendment #432). With no foreign key the
    // delete succeeds and the invoice keeps a dangling id, so a later classification falls back to name and rate.
    expect(fn () => DB::transaction(fn () => DB::table('tax_rules')->where('id', $ruleId)->delete()))->toThrow(QueryException::class);
    expect(DB::table('invoices')->where('id', $invoice->id)->value('tax1_rule_id'))->toBe($ruleId);
});
