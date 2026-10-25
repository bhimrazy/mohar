<?php

namespace App\Modules\Billing\Support;

use App\Contracts\Core\CompanyClock;
use App\Models\User;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\RequestMemo;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Dashboard\Dashboard;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Billing's part of the admin client page ("admin-client" dashboard area): the client's money in the client's own
 * currency (other currencies it holds are named in the hint), the outstanding count and the latest invoices.
 */
class BillingAdminClientSummary
{
    private const RECENT = 5;

    /** Invoices that still count towards what was paid (not cancelled, refunded or draft). */
    private const COUNTED = [InvoiceStatus::Unpaid, InvoiceStatus::PaymentPending, InvoiceStatus::Collections, InvoiceStatus::Paid];

    public function register(Dashboard $dashboard): void
    {
        $href = fn (Client $client, array $query = []): string => route('admin.billing.invoices.index', ['client' => $client->id, ...$query]);

        $dashboard->card('admin-client', 'billing.outstanding', fn (?User $user, Client $client): array => $this->money($client, 'Outstanding balance', 'outstanding', $href($client, ['status' => 'outstanding']), 'Receipt'), 'billing.invoices.view', 10);
        $dashboard->card('admin-client', 'billing.overdue', fn (?User $user, Client $client): array => [
            ...$this->money($client, 'Overdue', 'overdue', $href($client, ['status' => 'overdue']), 'Receipt'),
            'tone' => ($this->totals($client)[$client->currency]['overdue'] ?? 0) > 0 ? 'danger' : 'default',
        ], 'billing.invoices.view', 11);
        $dashboard->card('admin-client', 'billing.credit', fn (?User $user, Client $client): array => $this->money($client, 'Credit balance', 'credit', route('admin.billing.credit.index', ['client' => $client->id]), 'Wallet'), 'billing.credit.manage', 12);
        // amount_paid is net of refunds and chargebacks and leaves out account credit applied (the credit ledger holds that).
        $dashboard->card('admin-client', 'billing.paid', fn (?User $user, Client $client): array => $this->money($client, 'Payments received', 'paid', $href($client), 'ArrowLeftRight', 'Net of refunds, excl. account credit'), 'billing.invoices.view', 13);

        $dashboard->card('admin-client', 'billing.unpaid', function (?User $user, Client $client) use ($href): array {
            $count = collect($this->totals($client))->sum('outstanding_count');
            $overdue = collect($this->totals($client))->sum('overdue_count');

            return [
                'label' => 'Outstanding invoices',
                'value' => $count,
                'hint' => $overdue > 0 ? "{$overdue} overdue" : null,
                'href' => $href($client, ['status' => 'outstanding']),
                'icon' => 'FileText',
            ];
        }, 'billing.invoices.view', 20);

        $dashboard->widget('admin-client', 'billing.invoices', 'list', fn (?User $user, Client $client): array => $this->recent($client), 'billing.invoices.view', 30);
    }

    /**
     * @return array<string, mixed>
     */
    private function money(Client $client, string $label, string $figure, string $href, string $icon, ?string $note = null): array
    {
        $totals = $this->totals($client);
        $formatter = app(MoneyFormatter::class);
        $others = collect($totals)->except($client->currency)->filter(fn (array $row): bool => $row[$figure] > 0)
            ->map(fn (array $row, string $currency): string => $formatter->format(Money::of($row[$figure], $currency)))->values();

        return [
            'label' => $label,
            'money' => ['amount' => (int) ($totals[$client->currency][$figure] ?? 0), 'currency' => $client->currency],
            'hint' => collect([$note, $others->isEmpty() ? null : 'Also '.$others->implode(', ')])->filter()->implode('. ') ?: null,
            'href' => $href,
            'icon' => $icon,
            'primary' => true,
        ];
    }

    /**
     * One pass over the client's invoices (index client_id,status) and one over its credit ledger, shared by the cards.
     *
     * @return array<string, array{outstanding: int, overdue: int, paid: int, credit: int, outstanding_count: int, overdue_count: int}>
     */
    private function totals(Client $client): array
    {
        return RequestMemo::remember("billing.client-summary.{$client->id}", function () use ($client): array {
            $today = app(CompanyClock::class)->todayDate()->toDateString();
            $open = Invoice::OUTSTANDING_STATUSES;
            $rows = [];
            $blank = ['outstanding' => 0, 'overdue' => 0, 'paid' => 0, 'credit' => 0, 'outstanding_count' => 0, 'overdue_count' => 0];

            // Outstanding and its count match the invoice list's "outstanding" filter (Invoice::scopeOutstanding).
            $invoices = Invoice::query()->where('client_id', $client->id)->whereIn('status', array_map(fn (InvoiceStatus $status): string => $status->value, self::COUNTED))
                ->groupBy('currency')
                ->selectRaw(
                    'currency, '
                    .'sum(case when status in (?, ?, ?) and balance > 0 then balance else 0 end) as outstanding, '
                    .'sum(case when status = ? and due_date < ? then balance else 0 end) as overdue, '
                    .'sum(amount_paid) as paid, '
                    .'sum(case when status in (?, ?, ?) and balance > 0 then 1 else 0 end) as outstanding_count, '
                    .'sum(case when status = ? and due_date < ? then 1 else 0 end) as overdue_count',
                    [...$open, InvoiceStatus::Unpaid->value, $today, ...$open, InvoiceStatus::Unpaid->value, $today],
                )->toBase()->get();

            foreach ($invoices as $row) {
                $rows[$row->currency] = [...$blank, 'outstanding' => (int) $row->outstanding, 'overdue' => (int) $row->overdue, 'paid' => (int) $row->paid, 'outstanding_count' => (int) $row->outstanding_count, 'overdue_count' => (int) $row->overdue_count];
            }

            // The balance is the latest entry's balance_after per currency.
            $latest = CreditLedgerEntry::query()->where('client_id', $client->id)
                ->whereIn('id', DB::table('credit_ledger')->where('client_id', $client->id)->groupBy('currency')->selectRaw('max(id)'))
                ->get(['currency', 'balance_after']);

            foreach ($latest as $entry) {
                $rows[$entry->currency] = [...($rows[$entry->currency] ?? $blank), 'credit' => (int) $entry->balance_after];
            }

            return $rows;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function recent(Client $client): array
    {
        $today = app(CompanyClock::class)->todayDate();

        return [
            'title' => 'Invoices',
            'items' => Invoice::query()->where('client_id', $client->id)->where('status', '!=', InvoiceStatus::Draft->value)
                ->orderByDesc('issue_date')->orderByDesc('id')->limit(self::RECENT)
                ->get(['id', 'number', 'proforma_number', 'status', 'issue_date', 'due_date', 'total', 'currency'])
                ->map(fn (Invoice $invoice): array => [
                    'title' => $invoice->number ?? $invoice->proforma_number ?? 'Invoice',
                    'href' => route('admin.billing.invoices.show', $invoice->id),
                    'money' => ['amount' => $invoice->total, 'currency' => $invoice->currency],
                    'status' => $invoice->displayStatus($today),
                    'date' => $invoice->issue_date?->toDateString(),
                    'dateLabel' => 'Issued',
                ])->values()->all(),
            'href' => route('admin.billing.invoices.index', ['client' => $client->id]),
            'empty' => 'No invoices yet.',
        ];
    }
}
