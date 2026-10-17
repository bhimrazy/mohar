<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AcceptOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('orders.orders.accept') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'run_create' => ['nullable', 'array'],
            'run_create.*' => ['integer'],
        ];
    }

    /**
     * Item ids to provision, or null for the default selection.
     *
     * @return list<int>|null
     */
    public function runCreate(): ?array
    {
        return $this->has('run_create') ? array_values(array_map(intval(...), (array) $this->input('run_create', []))) : null;
    }
}
