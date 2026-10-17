<?php

namespace App\Modules\Domains\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TldPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('domains.tlds.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'client_group_id' => ['nullable', 'integer', Rule::exists('client_groups', 'id')],
            'prices' => ['required', 'array', 'max:10'],
            'prices.*.years' => ['required', 'integer', 'min:1', 'max:10', 'distinct'],
            'prices.*.register' => ['nullable', 'integer', 'min:0'],
            'prices.*.transfer' => ['nullable', 'integer', 'min:0'],
            'prices.*.renew' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return list<array{years: int, register: int|null, transfer: int|null, renew: int|null}>
     */
    public function rows(): array
    {
        $optional = fn (mixed $value): ?int => $value === null || $value === '' ? null : (int) $value;

        return array_values(array_map(fn (array $row): array => [
            'years' => (int) $row['years'],
            'register' => $optional($row['register'] ?? null),
            'transfer' => $optional($row['transfer'] ?? null),
            'renew' => $optional($row['renew'] ?? null),
        ], (array) $this->validated('prices')));
    }
}
