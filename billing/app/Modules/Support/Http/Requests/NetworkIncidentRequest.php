<?php

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Enums\IncidentPriority;
use App\Modules\Support\Enums\IncidentStatus;
use App\Modules\Support\Enums\IncidentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NetworkIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('support.network.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(IncidentType::class)],
            'server_id' => ['nullable', 'integer', 'min:1'],
            'affected' => ['nullable', 'string', 'max:255'],
            'priority' => ['required', Rule::enum(IncidentPriority::class)],
            'status' => ['required', Rule::enum(IncidentStatus::class)],
            'description' => ['required', 'string', 'max:20000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
