<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Http\Requests\MoneyInput;
use App\Modules\Billing\Services\GatewayManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDraftInvoiceRequest extends FormRequest
{
    use MoneyInput;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.invoices.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'due_date' => ['nullable', 'date'],
            'payment_gateway' => ['nullable', 'string', Rule::in(array_keys(app(GatewayManager::class)->drivers()))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_amount' => ['required', self::SIGNED_AMOUNT],
            'items.*.taxable' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => __('Add at least one line.'),
            'items.*.unit_amount.regex' => __('Enter an amount like 12.50.'),
        ];
    }

    /**
     * @return array{client_id: int, due_date: string|null, payment_gateway: string|null, notes: string|null, admin_notes: string|null, items: list<array{description: string, quantity: int, unit_amount: int, taxable: bool}>}
     */
    public function draft(): array
    {
        /** @var list<array{description: string, quantity: int|string, unit_amount: string, taxable?: bool}> $items */
        $items = array_values($this->validated('items'));

        return [
            'client_id' => $this->integer('client_id'),
            'due_date' => $this->validated('due_date'),
            'payment_gateway' => $this->validated('payment_gateway'),
            'notes' => $this->validated('notes'),
            'admin_notes' => $this->validated('admin_notes'),
            'items' => array_map(fn (array $item): array => [
                'description' => $item['description'],
                'quantity' => (int) $item['quantity'],
                'unit_amount' => self::toMinor($item['unit_amount']),
                'taxable' => (bool) ($item['taxable'] ?? true),
            ], $items),
        ];
    }
}
