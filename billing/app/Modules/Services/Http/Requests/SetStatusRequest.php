<?php

namespace App\Modules\Services\Http\Requests;

use App\Modules\Services\Enums\ServiceStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('services.services.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ServiceStatus::class)],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }
}
