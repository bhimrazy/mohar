<?php

namespace App\Modules\Billing\Http\Requests\Admin;

use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Http\Requests\MoneyInput;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The note staff preview and confirm (vat-register §5.1): a credit note by amount or by lines, a debit note, or the
 * reversal of a credit note (`reverses`). The confirm also carries the preview's `version` and `digest`.
 */
class TaxNoteRequest extends FormRequest
{
    use MoneyInput;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('billing.notes.issue') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reversal = $this->filled('reverses');
        $byLines = $this->input('kind') === 'credit' && $this->input('allocation') === 'lines';

        return [
            'reverses' => ['nullable', 'integer'],
            'kind' => [Rule::requiredIf(! $reversal), Rule::in(['credit', 'debit'])],
            'allocation' => [Rule::requiredIf($this->input('kind') === 'credit' && ! $reversal), Rule::in(['amount', 'lines'])],
            'amount' => [Rule::requiredIf(! $reversal && ! $byLines), 'nullable', self::AMOUNT],
            'lines' => [Rule::requiredIf($byLines), 'array', 'max:500'],
            'lines.*' => ['nullable', self::AMOUNT],
            'taxable' => ['boolean'],
            'reason_code' => [Rule::requiredIf(! $reversal), 'nullable', Rule::in([...TaxNoteIssuer::CREDIT_REASONS, ...TaxNoteIssuer::DEBIT_REASONS])],
            'reason_text' => ['required', 'string', 'max:500'],
            'settlement' => ['nullable', Rule::in(NoteRequest::SETTLEMENTS)],
            'version' => [Rule::requiredIf($this->isMethod('POST')), 'integer'],
            'digest' => [Rule::requiredIf($this->isMethod('POST')), 'string', 'size:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason_code' => 'reason', 'reason_text' => 'reason shown to the client', 'lines.*' => 'line amount'];
    }

    public function note(Invoice $invoice): NoteRequest
    {
        $userId = $this->user()?->id;
        $text = (string) $this->input('reason_text');

        if ($this->filled('reverses')) {
            return NoteRequest::reversal($invoice->id, $this->integer('reverses'), $text, $userId);
        }

        $code = (string) $this->input('reason_code');

        if ($this->input('kind') === 'debit') {
            return NoteRequest::debit($invoice->id, $this->minor('amount', $invoice->currency), $this->boolean('taxable', true), $code, $text, userId: $userId);
        }

        $settlement = (string) ($this->input('settlement') ?? 'balance');

        if ($this->input('allocation') === 'lines') {
            $lines = array_map(fn (mixed $amount): int => Money::fromDecimal((string) $amount, $invoice->currency)->amount, array_filter((array) $this->input('lines'), fn (mixed $amount): bool => is_numeric($amount)));

            return NoteRequest::creditLines($invoice->id, array_filter($lines, fn (int $amount): bool => $amount > 0), $code, $text, userId: $userId, settlement: $settlement);
        }

        return NoteRequest::creditAmount($invoice->id, $this->minor('amount', $invoice->currency), $code, $text, userId: $userId, settlement: $settlement);
    }
}
