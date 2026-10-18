<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Http\Requests\MoneyInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteRequest extends FormRequest
{
    use MoneyInput;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.quotes.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'subject' => ['required', 'string', 'max:255'],
            'valid_until' => ['nullable', 'date'],
            'proposal_text' => ['nullable', 'string', 'max:10000'],
            'customer_notes' => ['nullable', 'string', 'max:5000'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_amount' => ['required', self::SIGNED_AMOUNT],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.taxable' => ['boolean'],
        ];
    }

    /**
     * @return array{client_id: int, subject: string, valid_until: string|null, proposal_text: string|null, customer_notes: string|null, admin_notes: string|null, items: list<array{description: string, quantity: int, unit_amount: int, discount_percent: float, taxable: bool}>}
     */
    public function quote(): array
    {
        /** @var list<array{description: string, quantity: int|string, unit_amount: string, discount_percent?: string|float|null, taxable?: bool}> $items */
        $items = array_values($this->validated('items'));

        return [
            'client_id' => $this->integer('client_id'),
            'subject' => $this->string('subject')->toString(),
            'valid_until' => $this->validated('valid_until'),
            'proposal_text' => $this->validated('proposal_text'),
            'customer_notes' => $this->validated('customer_notes'),
            'admin_notes' => $this->validated('admin_notes'),
            'items' => array_map(fn (array $item): array => [
                'description' => $item['description'],
                'quantity' => (int) $item['quantity'],
                'unit_amount' => self::toMinor($item['unit_amount']),
                'discount_percent' => (float) ($item['discount_percent'] ?? 0),
                'taxable' => (bool) ($item['taxable'] ?? true),
            ], $items),
        ];
    }
}
