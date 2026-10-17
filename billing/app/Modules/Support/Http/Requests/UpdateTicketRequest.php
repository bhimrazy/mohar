<?php

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff ticket options (status, priority, department, assignee, cc, tags, ...). Only sent keys change.
 */
class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('support.tickets.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['cc', 'tags'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => array_values(array_filter(array_map('trim', explode(',', (string) $this->input($key)))))]);
            }
        }

        foreach (['assigned_to', 'service_id', 'client_id'] as $key) {
            if ($this->has($key) && in_array($this->input($key), ['', '0', 0, 'none'], true)) {
                $this->merge([$key => null]);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(TicketStatus::class)],
            'priority' => ['sometimes', Rule::enum(TicketPriority::class)],
            'department_id' => ['sometimes', 'integer', Rule::exists('support_departments', 'id')],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_staff', true)],
            'subject' => ['sometimes', 'string', 'max:255'],
            'cc' => ['sometimes', 'array', 'max:10'],
            'cc.*' => ['email', 'max:255'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'is_pinned' => ['sometimes', 'boolean'],
            'prevent_client_close' => ['sometimes', 'boolean'],
            'service_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'client_id' => ['sometimes', 'nullable', 'integer', Rule::exists('clients', 'id')],
            'version' => ['nullable', 'integer'],
            'force' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{status?: string, priority?: string, department_id?: int, assigned_to?: int|null, subject?: string, cc?: list<string>, tags?: list<string>, is_pinned?: bool, prevent_client_close?: bool, service_id?: int|null, client_id?: int|null}
     */
    public function changes(): array
    {
        $data = $this->safe()->except(['version', 'force']);
        $changes = [];

        foreach (['status', 'priority', 'subject'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = (string) $data[$key];
            }
        }

        foreach (['department_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = (int) $data[$key];
            }
        }

        foreach (['assigned_to', 'service_id', 'client_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = $data[$key] === null ? null : (int) $data[$key];
            }
        }

        foreach (['cc', 'tags'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = array_values(array_map('strval', (array) $data[$key]));
            }
        }

        foreach (['is_pinned', 'prevent_client_close'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = (bool) $data[$key];
            }
        }

        return $changes;
    }
}
