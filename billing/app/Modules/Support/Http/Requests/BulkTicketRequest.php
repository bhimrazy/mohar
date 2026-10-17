<?php

namespace App\Modules\Support\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkTicketRequest extends FormRequest
{
    public const ACTIONS = ['close', 'assign', 'delete', 'block_sender'];

    public function authorize(): bool
    {
        $permission = $this->input('action') === 'delete' ? 'support.tickets.delete' : 'support.tickets.manage';

        return $this->user()?->hasPermission($permission) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(self::ACTIONS)],
            'tickets' => ['required', 'array', 'min:1', 'max:100'],
            'tickets.*' => ['integer', Rule::exists('support_tickets', 'id')],
            'assigned_to' => ['required_if:action,assign', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_staff', true)],
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
