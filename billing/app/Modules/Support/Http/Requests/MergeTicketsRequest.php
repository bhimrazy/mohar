<?php

namespace App\Modules\Support\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MergeTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('support.tickets.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tickets' => ['required', 'array', 'min:2', 'max:20'],
            'tickets.*' => ['integer', 'distinct', Rule::exists('support_tickets', 'id')],
        ];
    }

    /**
     * @return list<int>
     */
    public function ticketIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('tickets')));
    }
}
