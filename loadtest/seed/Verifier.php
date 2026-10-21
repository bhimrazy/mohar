<?php

declare(strict_types=1);

namespace LoadTest\Seed;

use App\Modules\Billing\Services\DocumentNumbers;
use App\Modules\Billing\Support\BillingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Invariant checks on a seeded database (run.php --verify, and after every seed). Each check counts the rows that
 * break it; a clean database reports zero everywhere. Numbering is checked from 1 per reset period, so it applies to
 * a fresh install, which is what the load test always starts from.
 */
final class Verifier
{
    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        return [
            'invoice_numbers_out_of_sequence' => $this->numbering(),
            'invoice_subtotal_not_sum_of_lines' => $this->count(
                'SELECT COUNT(*) AS n FROM invoices i LEFT JOIN (SELECT invoice_id, SUM(amount) AS s FROM invoice_items WHERE cancelled_at IS NULL GROUP BY invoice_id) t
                    ON t.invoice_id = i.id WHERE i.subtotal <> COALESCE(t.s, 0)'),
            'invoice_total_not_subtotal_less_discount_plus_tax' => $this->count(
                'SELECT COUNT(*) AS n FROM invoices WHERE total <> subtotal - discount_total + tax1_amount + tax2_amount AND tax_inclusive = ?', [false]),
            'invoice_balance_wrong' => $this->count(
                "SELECT COUNT(*) AS n FROM invoices WHERE status IN ('unpaid', 'payment_pending', 'collections', 'paid') AND balance <> total + adjustments_total - amount_paid"),
            'paid_invoice_not_fully_paid' => $this->count("SELECT COUNT(*) AS n FROM invoices WHERE status = 'paid' AND (balance <> 0 OR paid_at IS NULL)"),
            'paid_amount_not_sum_of_transactions' => $this->count(
                "SELECT COUNT(*) AS n FROM invoices i LEFT JOIN (SELECT invoice_id, SUM(amount_in - amount_out) AS s FROM transactions WHERE status = 'succeeded' GROUP BY invoice_id) t
                    ON t.invoice_id = i.id WHERE i.status = 'paid' AND i.amount_paid <> COALESCE(t.s, 0)"),
            'invoice_currency_not_client_currency' => $this->count(
                'SELECT COUNT(*) AS n FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.currency <> c.currency'),
            'transaction_currency_not_invoice_currency' => $this->count(
                'SELECT COUNT(*) AS n FROM transactions t JOIN invoices i ON i.id = t.invoice_id WHERE t.currency <> i.currency'),
            'renewal_line_without_service_link' => $this->count(
                "SELECT COUNT(*) AS n FROM invoice_items ii LEFT JOIN service_invoices si ON si.invoice_id = ii.invoice_id AND si.service_id = ii.billable_id
                    WHERE ii.type = 'service' AND si.id IS NULL"),
            'service_due_after_next_invoice' => $this->count(
                "SELECT COUNT(*) AS n FROM services WHERE status IN ('active', 'suspended') AND cycle NOT IN ('onetime', 'free')
                    AND (next_due_date IS NULL OR next_invoice_date IS NULL OR next_due_date > next_invoice_date)"),
            'suspended_service_without_open_invoice' => $this->count(
                "SELECT COUNT(*) AS n FROM services s WHERE s.status = 'suspended' AND NOT EXISTS (
                    SELECT 1 FROM service_invoices si JOIN invoices i ON i.id = si.invoice_id WHERE si.service_id = s.id AND i.status = 'unpaid')"),
            'service_currency_not_client_currency' => $this->count(
                'SELECT COUNT(*) AS n FROM services s JOIN clients c ON c.id = s.client_id WHERE s.currency <> c.currency'),
        ];
    }

    /**
     * Numbers in id order must be exactly the formatted 1, 1 + increment, ... of each reset period, and each
     * sequence row must point one increment past the last number used.
     */
    private function numbering(): int
    {
        $settings = app(BillingSettings::class);
        $numbers = app(DocumentNumbers::class);
        $reset = $settings->string('number_reset', 'never');
        $increment = max(1, $settings->int('number_increment', 1));
        $format = $settings->string('number_format', '{NUMBER}');
        $format = str_contains($format, '{NUMBER}') ? $format : $format.'{NUMBER}';
        $next = [];
        $wrong = 0;

        foreach (DB::table('invoices')->whereNotNull('number')->select(['id', 'number', 'issue_date'])->lazyById(5_000) as $invoice) {
            $date = CarbonImmutable::parse((string) $invoice->issue_date);
            $period = match ($reset) {
                'yearly' => $date->format('Y'),
                'monthly' => $date->format('Y-m'),
                default => '',
            };
            $next[$period] ??= 1;
            $wrong += $numbers->format($format, $next[$period], $date) === $invoice->number ? 0 : 1;
            $next[$period] += $increment;
        }

        foreach ($next as $period => $expected) {
            $stored = DB::table('billing_sequences')->where('sequence', DocumentNumbers::INVOICE)->where('period', $period)->value('next_number');
            $wrong += (int) $stored === $expected ? 0 : 1;
        }

        return $wrong;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function count(string $sql, array $bindings = []): int
    {
        return (int) (DB::selectOne($sql, $bindings)->n ?? 0);
    }
}
