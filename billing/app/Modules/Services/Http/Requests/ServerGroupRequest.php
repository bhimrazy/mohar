<?php

namespace App\Modules\Services\Http\Requests;

use App\Modules\Services\Enums\FillType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServerGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('services.servers.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'fill_type' => ['required', Rule::enum(FillType::class)],
            'server_ids' => ['array'],
            'server_ids.*' => ['integer', 'distinct', Rule::exists('servers', 'id')],
        ];
    }
}
