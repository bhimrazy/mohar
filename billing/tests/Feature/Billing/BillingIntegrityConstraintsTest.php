<?php

/*
| Database review 2026-10-31, H1, M3 and M5: the keys and checks that keep money history and the tax rule behind an
| invoice from being lost or written wrong. DatabaseArchitectureProbesTest holds the review's own probes.
*/

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/helpers.php';

$pgsqlOnly = fn () => DB::connection()->getDriverName() !== 'pgsql';

it('refuses to delete a client with money history', function (Closure $history, string $table) {
    $client = billingClient();
    $history($client);

    expect(fn () => DB::transaction(fn () => DB::table('clients')->where('id', $client->id)->delete()))->toThrow(QueryException::class)
        ->and(DB::table($table)->where('client_id', $client->id)->count())->toBe(1);
})->with([
    'a payment attempt' => [fn (Client $client) => DB::table('payment_attempts')->insert(['reference' => PaymentAttempt::newReference(), 'gateway' => 'sandbox_card', 'client_id' => $client->id,
        'amount' => 5_000, 'currency' => $client->currency, 'document_amount' => 5_000, 'document_currency' => $client->currency, 'created_at' => now(), 'updated_at' => now()]), 'payment_attempts'],
    'a payment batch' => [fn (Client $client) => DB::table('payment_batches')->insert(['client_id' => $client->id, 'currency' => $client->currency, 'invoice_ids' => '[]', 'total' => 5_000,
        'created_at' => now(), 'updated_at' => now()]), 'payment_batches'],
]);

test('a rule an invoice starts using between the count and the delete is kept, and the caller can go on', function () {
    $rule = TaxRule::factory()->create();
    $invoice = issueInvoice(billingClient());
    // The invoice picks the rule after deleteUnlessUsed has counted none: the foreign key refuses the delete.
    TaxRule::deleting(fn (TaxRule $deleting) => DB::table('invoices')->where('id', $invoice->id)->update(['tax1_rule_id' => $deleting->id]));

    expect($rule->deleteUnlessUsed())->toBe(1)
        ->and(TaxRule::query()->whereKey($rule->id)->exists())->toBeTrue();
});

test('staff are told why a tax rule an invoice was calculated with cannot be deleted', function () {
    $rule = TaxRule::factory()->create();
    $invoices = [issueInvoice(billingClient()), issueInvoice(billingClient())];
    DB::table('invoices')->where('id', $invoices[0]->id)->update(['tax1_rule_id' => $rule->id]);
    DB::table('invoices')->where('id', $invoices[1]->id)->update(['tax2_rule_id' => $rule->id]);
    actingAsStaff(['billing.tax.manage']);
    confirmPassword();

    $this->delete(route('admin.billing.tax-rules.destroy', $rule))->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This rule was used to calculate 2 invoices, so it can\'t be deleted: the invoices keep it as the record of their tax. To stop it applying to new invoices, add a rule for the same place with a higher priority or a later effective date.']);

    expect($rule->fresh())->not->toBeNull()
        ->and(DB::table('activity_log')->where('description', 'Tax rule deleted')->exists())->toBeFalse();
});

it('refuses values the billing rules never allow', function (Closure $write) {
    $invoice = issueInvoice(billingClient());

    expect(fn () => DB::transaction(fn () => $write($invoice)))->toThrow(QueryException::class);
})->with([
    'a tax rate above 100' => [fn () => TaxRule::factory()->create(['rate' => '150.000'])],
    'a negative tax rate' => [fn () => TaxRule::factory()->create(['rate' => '-1.000'])],
    'a line quantity of 0' => [fn ($invoice) => DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['quantity' => 0])],
    'an invoice status outside the enum' => [fn ($invoice) => DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'Paid'])],
    'a negative total on an issued invoice' => [fn ($invoice) => DB::table('invoices')->where('id', $invoice->id)->update(['total' => -1])],
    'a negative amount paid' => [fn ($invoice) => DB::table('invoices')->where('id', $invoice->id)->update(['amount_paid' => -1])],
    'a negative credit applied' => [fn ($invoice) => DB::table('invoices')->where('id', $invoice->id)->update(['credit_applied' => -1])],
    'a transaction status outside the enum' => [fn ($invoice) => DB::table('transactions')->where('id', Transaction::factory()->create(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id])->id)->update(['status' => 'done'])],
    'a negative fee' => [fn ($invoice) => DB::table('transactions')->where('id', Transaction::factory()->create(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id])->id)->update(['fee' => -1])],
    'a negative amount out' => [fn ($invoice) => DB::table('transactions')->where('id', Transaction::factory()->create(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id])->id)->update(['amount_out' => -1])],
])->skip($pgsqlOnly, 'The checks are PostgreSQL only (SQLite cannot add a CHECK to an existing table)');

test('a draft and a cancelled draft may carry a negative total until the invoice is published', function () {
    $invoice = issueInvoice(billingClient(), status: 'draft');

    DB::table('invoices')->where('id', $invoice->id)->update(['total' => -500]);
    DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'cancelled']);

    expect(DB::table('invoices')->where('id', $invoice->id)->value('total'))->toBe(-500);
})->skip($pgsqlOnly, 'The checks are PostgreSQL only');

test('each status check lists exactly the cases of its enum, so a new case comes with a migration', function (string $constraint, array $cases) {
    $definition = (string) DB::table('pg_constraint')->where('conname', $constraint)->value(DB::raw('pg_get_constraintdef(oid)'));
    preg_match_all("/'([a-z_]+)'::/", $definition, $values);

    expect($values[1])->toEqualCanonicalizing(array_map(fn (BackedEnum $case): string => $case->value, $cases));
})->with([
    'invoices' => ['invoices_status_check', InvoiceStatus::cases()],
    'transactions' => ['transactions_status_check', TransactionStatus::cases()],
])->skip($pgsqlOnly, 'The checks are PostgreSQL only');

test('invoices that name a deleted tax rule are logged and kept, and the key waits for staff to validate it', function () {
    $migration = require base_path('app/Modules/Billing/Database/Migrations/2026_11_04_300820_add_tax_rule_foreign_keys_to_invoices.php');
    $migration->down();
    $invoice = issueInvoice(billingClient());
    DB::table('invoices')->where('id', $invoice->id)->update(['tax2_rule_id' => 987_654]);
    Log::spy();

    $migration->up();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['column'] === 'tax2_rule_id' && $context['invoice_ids'] === [$invoice->id]);
    expect(DB::table('invoices')->where('id', $invoice->id)->value('tax2_rule_id'))->toBe(987_654)
        ->and(DB::table('pg_constraint')->whereIn('conname', ['invoices_tax1_rule_id_foreign', 'invoices_tax2_rule_id_foreign'])->pluck('convalidated', 'conname')->all())
        ->toEqual(['invoices_tax1_rule_id_foreign' => true, 'invoices_tax2_rule_id_foreign' => false]);
})->skip($pgsqlOnly, 'NOT VALID keys are PostgreSQL only');

test('rows that break a check stop the migration by id, and the check is not left half applied', function () {
    $migration = require base_path('app/Modules/Billing/Database/Migrations/2026_11_04_300830_add_check_constraints_to_billing_tables.php');
    $migration->down();
    $invoice = issueInvoice(billingClient());
    $line = DB::table('invoice_items')->where('invoice_id', $invoice->id)->value('id');
    DB::table('invoice_items')->where('id', $line)->update(['quantity' => 0]);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, "invoice_items: 1 rows break invoice_items_quantity_check (quantity > 0), ids {$line}. The checks of this table not yet validated were removed again")
        ->and(DB::table('pg_constraint')->where('conname', 'invoice_items_quantity_check')->exists())->toBeFalse()
        ->and(DB::table('pg_constraint')->where('conname', 'tax_rules_rate_check')->value('convalidated'))->toBeTrue();
})->skip($pgsqlOnly, 'The checks are PostgreSQL only');
