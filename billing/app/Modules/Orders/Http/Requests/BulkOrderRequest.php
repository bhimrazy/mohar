<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BulkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:accept,cancel,delete'],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ];
    }

    public function permission(): string
    {
        return match ($this->string('action')->toString()) {
            'accept' => 'orders.orders.accept',
            'cancel' => 'orders.orders.cancel',
            default => 'orders.orders.delete',
        };
    }
}
