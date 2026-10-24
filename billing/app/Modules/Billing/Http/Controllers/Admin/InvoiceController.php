<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Contracts\Billing\GatewayDriver;
use App\Contracts\Billing\LooksUpRefunds;
use App\Contracts\Core\CompanyClock;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\SaveDraftInvoice;
use App\Modules\Billing\Actions\UpdateInvoiceDetails;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\ImmutableInvoiceException;
use App\Modules\Billing\Http\Requests\Admin\SaveDraftInvoiceRequest;
use App\Modules\Billing\Http\Resources\InvoiceResource;
use App\Modules\Billing\Http\Resources\PayMethodResource;
use App\Modules\Billing\Http\Resources\TransactionResource;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\NepalVatMode;
use App\Modules\Billing\Services\NoteRefusals;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Billing\Support\PaymentInProgress;
use App\Modules\Billing\Support\TaxNotePanel;
use App\Modules\Core\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function __construct(private GatewayManager $gateways) {}

    public function index(Request $request): Response
    {
        $this->authorize('billing.invoices.view');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...array_column(InvoiceStatus::cases(), 'value'), 'overdue'])],
            'client' => ['nullable', 'integer'],
            'gateway' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // Only the invoices a cancel or a late fee was refused on (Needs attention), while they are still payable.
            'refused' => ['nullable', 'boolean'],
        ]);

        $invoices = Invoice::query()
            ->with('client:id,first_name,last_name,company_name')
            ->search($filters['search'] ?? null)
            ->displayStatus($filters['status'] ?? null)
            ->when($filters['client'] ?? null, fn ($query, $client) => $query->where('client_id', $client))
            ->when($filters['gateway'] ?? null, fn ($query, $gateway) => $query->where('payment_gateway', $gateway))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('issue_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('issue_date', '<=', $to))
            ->when($filters['refused'] ?? null, fn ($query) => $query->whereIn('id', app(NoteRefusals::class)->invoiceIds()))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $client = isset($filters['client']) ? Client::query()->find($filters['client']) : null;

        return Inertia::render('admin/billing/invoices/index', [
            'invoices' => InvoiceResource::collection($invoices),
            'filters' => (object) array_filter($filters),
            'statuses' => $this->statusOptions(),
            'gateways' => $this->gatewayOptions(),
            'client' => $client?->only(['id', 'display_name']),
            'can' => ['manage' => $request->user()?->hasPermission('billing.invoices.manage') ?? false],
        ]);
    }

    public function create(Request $request, BillingSettings $settings, CompanyClock $clock): Response
    {
        $this->authorize('billing.invoices.manage');

        return Inertia::render('admin/billing/invoices/form', [
            'invoice' => null,
            'client' => $this->clientOption($request->integer('client') ?: null),
            'defaults' => [
                'client_id' => $request->integer('client') ?: null,
                'due_date' => $clock->today()->addDays($settings->int('invoice_days_before_due', 14))->toDateString(),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function store(SaveDraftInvoiceRequest $request, SaveDraftInvoice $save): RedirectResponse
    {
        $invoice = $save->handle($request->draft());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft invoice created.')]);

        return to_route('admin.billing.invoices.show', $invoice);
    }

    public function show(Request $request, Invoice $invoice, CreditLedgerService $credit, PaymentInProgress $inProgress, TaxNotePanel $notes): Response
    {
        $this->authorize('billing.invoices.view');

        $invoice->load(['client', 'items', 'adjustments.creator:id,name']);
        $user = $request->user();

        return Inertia::render('admin/billing/invoices/show', [
            'invoice' => new InvoiceResource($invoice),
            'transactions' => TransactionResource::collection($invoice->transactions()->get()),
            'credit_available' => $credit->balance($invoice->client_id, $invoice->currency)->amount,
            'payment_in_progress' => $invoice->status->isPayable() ? $inProgress->forInvoice($invoice, staff: true) : null,
            'gateways' => $this->gatewayOptions(),
            'pay_methods' => PayMethodResource::collection(PayMethod::query()->where('client_id', $invoice->client_id)->usable()->orderByDesc('is_default')->orderBy('id')->get()
                ->filter(fn (PayMethod $method): bool => $method->isUsable() && $this->gateways->supports($method->gateway, GatewayDriver::CAPTURE))->values()),
            ...$notes->props($invoice, $user),
            // Cancelling a numbered tax invoice issues a credit note and reopening is refused, from the mode's start day on.
            'fiscal_cancel' => $invoice->numbered_at !== null && $invoice->kind !== InvoiceKind::AddFunds && app(NepalVatMode::class)->inEffect(now()),
            'can' => [
                'manage' => $user?->hasPermission('billing.invoices.manage') ?? false,
                'payments' => $user?->hasPermission('billing.payments.manage') ?? false,
                'refunds' => $user?->hasPermission('billing.refunds.manage') ?? false,
                'notes' => $user?->hasPermission('billing.tax_notes.issue') ?? false,
            ],
        ]);
    }

    public function edit(Invoice $invoice): Response|RedirectResponse
    {
        $this->authorize('billing.invoices.manage');

        if ($invoice->status !== InvoiceStatus::Draft) {
            Inertia::flash('toast', ['type' => 'error', 'message' => ImmutableInvoiceException::make()->getMessage()]);

            return to_route('admin.billing.invoices.show', $invoice);
        }

        $invoice->load('items');

        return Inertia::render('admin/billing/invoices/form', [
            'invoice' => new InvoiceResource($invoice),
            'client' => $this->clientOption($invoice->client_id),
            'defaults' => ['client_id' => $invoice->client_id, 'due_date' => $invoice->due_date?->toDateString()],
            ...$this->formOptions(),
        ]);
    }

    public function update(SaveDraftInvoiceRequest $request, Invoice $invoice, SaveDraftInvoice $save): RedirectResponse
    {
        $save->handle($request->draft(), $invoice);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft invoice saved.')]);

        return to_route('admin.billing.invoices.show', $invoice);
    }

    /**
     * Fields that stay editable after issue.
     */
    public function updateDetails(Request $request, Invoice $invoice, UpdateInvoiceDetails $update): RedirectResponse
    {
        $this->authorize('billing.invoices.manage');

        $data = $request->validate([
            'due_date' => ['sometimes', 'nullable', 'date'],
            'payment_gateway' => ['sometimes', 'nullable', Rule::in(array_keys($this->gateways->drivers()))],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'admin_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $update->handle($invoice, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice :number updated.', ['number' => $invoice->displayNumber()])]);

        return back();
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->authorize('billing.invoices.manage');

        if ($invoice->status !== InvoiceStatus::Draft) {
            return back()->withErrors(['invoice' => __('Only draft invoices can be deleted. Cancel issued invoices instead.')]);
        }

        $invoice->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft invoice deleted.')]);

        return to_route('admin.billing.invoices.index');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return [
            ...array_map(fn (InvoiceStatus $status): array => ['value' => $status->value, 'label' => $status->label()], InvoiceStatus::cases()),
            ['value' => 'overdue', 'label' => 'Overdue'],
        ];
    }

    /**
     * @return list<array{value: string, label: string, capture: bool, refund: bool, partial_refund: bool, refund_lookup: bool, sandbox: bool}>
     */
    private function gatewayOptions(): array
    {
        return array_values(array_map(function (GatewayDriver $driver): array {
            $model = $this->gateways->gateway($driver->key());

            return [
                'value' => $driver->key(),
                'label' => $model->display_name,
                'capture' => $driver->supports(GatewayDriver::CAPTURE),
                'refund' => $driver->supports(GatewayDriver::REFUND),
                'partial_refund' => $driver->supports(GatewayDriver::PARTIAL_REFUND),
                'refund_lookup' => $driver instanceof LooksUpRefunds,
                'sandbox' => $this->gateways->isSandbox($model),
            ];
        }, $this->gateways->drivers()));
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'gateways' => $this->gatewayOptions(),
        ];
    }

    /**
     * @return array{id: int, display_name: string, currency: string}|null
     */
    private function clientOption(?int $id): ?array
    {
        $client = $id !== null ? Client::query()->find($id) : null;

        return $client === null ? null : ['id' => $client->id, 'display_name' => $client->display_name, 'currency' => $client->currency];
    }
}
