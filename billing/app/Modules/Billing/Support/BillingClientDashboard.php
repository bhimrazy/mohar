<?php

namespace App\Modules\Billing\Support;

use App\Contracts\Core\CompanyClock;
use App\Models\User;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Core\Support\DateFormats;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Dashboard\Dashboard;
use App\Support\Money;

/**
 * Billing's part of the client dashboard: the due-invoice banner, the unpaid and credit counters and the recent
 * invoices panel. All of it is hidden from account users without the billing area.
 */
class BillingClientDashboard
{
    /** Invoices the banner asks the client to pay; payment_pending is already being paid. */
    private const DUE = [InvoiceStatus::Unpaid, InvoiceStatus::Collections];

    public function register(Dashboard $dashboard): void
    {
        $dashboard->widget('client', 'billing.due', 'notice', fn (?User $user, Client $client): ?array => $this->canSeeBilling($user, $client) ? $this->dueNotice($client) : null, order: 0, size: 'lg');

        $dashboard->card('client', 'billing.unpaid', function (?User $user, Client $client): ?array {
            if (! $this->canSeeBilling($user, $client)) {
                return null;
            }

            $unpaid = Invoice::query()->where('client_id', $client->id)->whereIn('status', InvoiceStatus::payable());

            return [
                'label' => 'Unpaid invoices',
                ...$this->moneyValue(Money::of((int) (clone $unpaid)->sum('balance'), $client->currency)),
                'hint' => (clone $unpaid)->count().' to pay',
                'href' => route('client.invoices.index', ['status' => 'unpaid']),
                'icon' => 'Receipt',
            ];
        }, order: 10);

        $dashboard->card('client', 'billing.credit', fn (?User $user, Client $client): ?array => $this->canSeeBilling($user, $client) ? [
            'label' => 'Credit balance',
            ...$this->moneyValue(app(CreditLedgerService::class)->balance($client->id, $client->currency)),
            'href' => route('client.credit.index'),
            'icon' => 'Wallet',
        ] : null, order: 20);

        $dashboard->widget('client', 'billing.invoices', 'list', fn (?User $user, Client $client): ?array => $this->canSeeBilling($user, $client) ? $this->recentInvoices($client) : null, order: 20);
    }

    private function canSeeBilling(?User $user, Client $client): bool
    {
        return app(ClientContext::class)->allows($user, $client, 'billing');
    }

    /**
     * The invoice due first, with how many others are due. Null when nothing is owed.
     *
     * @return array<string, mixed>|null
     */
    private function dueNotice(Client $client): ?array
    {
        $due = Invoice::query()->where('client_id', $client->id)->whereIn('status', self::DUE)->where('balance', '>', 0);

        /** @var Invoice|null $first */
        $first = (clone $due)->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('id')
            ->first(['id', 'number', 'proforma_number', 'due_date', 'balance', 'currency']);

        if ($first === null) {
            return null;
        }

        $others = (clone $due)->count() - 1;
        $amount = app(MoneyFormatter::class)->amount($first->balance, $first->currency);
        $label = $first->number ?? $first->proforma_number ?? '#'.$first->id;
        $dueOn = $first->due_date?->format(DateFormats::current());
        $overdue = $first->due_date !== null && $first->due_date->lt(app(CompanyClock::class)->todayDate());
        $title = match (true) {
            $first->due_date === null => "Invoice {$label} for {$amount} is unpaid",
            $overdue => "Invoice {$label} for {$amount} was due on {$dueOn}",
            default => "Invoice {$label} for {$amount} is due on {$dueOn}",
        };

        return [
            'title' => $title,
            'body' => match (true) {
                $others > 0 => $others === 1 ? 'You have 1 more invoice to pay.' : "You have {$others} more invoices to pay.",
                $overdue => 'Pay now to keep your services running without interruption.',
                default => 'Pay before the due date to keep your services running without interruption.',
            },
            'tone' => $overdue ? 'danger' : 'warning',
            'actions' => [
                $others > 0
                    ? ['label' => 'View invoices', 'href' => route('client.invoices.index', ['status' => 'unpaid'])]
                    : ['label' => 'View invoice', 'href' => route('client.invoices.show', $first->id)],
                ['label' => "Pay {$amount}", 'href' => route('client.invoices.show', $first->id), 'primary' => true],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recentInvoices(Client $client): array
    {
        $clock = app(CompanyClock::class);
        $today = $clock->todayDate();

        $items = Invoice::query()->where('client_id', $client->id)->where('status', '!=', InvoiceStatus::Draft)
            ->orderByDesc('issue_date')->orderByDesc('id')->limit(4)
            ->get(['id', 'number', 'proforma_number', 'status', 'issue_date', 'due_date', 'paid_at', 'total', 'currency'])
            ->map(function (Invoice $invoice) use ($clock, $today): array {
                $overdue = $invoice->status === InvoiceStatus::Unpaid && $invoice->due_date?->lt($today);
                $paid = $invoice->status === InvoiceStatus::Paid && $invoice->paid_at !== null;

                return [
                    'title' => $invoice->number ?? $invoice->proforma_number ?? '#'.$invoice->id,
                    'href' => route('client.invoices.show', $invoice->id),
                    'money' => ['amount' => $invoice->total, 'currency' => $invoice->currency],
                    'status' => $overdue ? 'overdue' : $invoice->status->value,
                    'date' => ($paid ? $clock->localDate($invoice->paid_at) : $invoice->due_date?->toDateString()),
                    'dateLabel' => $paid ? 'Paid' : 'Due',
                ];
            })->values()->all();

        return [
            'title' => 'Recent invoices',
            'items' => $items,
            'href' => route('client.invoices.index'),
            'empty' => 'No invoices yet. They appear here when you order or renew a service.',
        ];
    }

    /**
     * @return array{value: string, money: array{amount: int, currency: string}}
     */
    private function moneyValue(Money $money): array
    {
        return [
            'value' => app(MoneyFormatter::class)->format($money),
            'money' => ['amount' => $money->amount, 'currency' => $money->currency],
        ];
    }
}
