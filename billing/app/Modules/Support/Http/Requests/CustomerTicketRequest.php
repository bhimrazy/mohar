<?php

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Http\Requests\Concerns\ValidatesAttachments;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A client (signed in) or guest opens a ticket. Department eligibility is checked by the controller
 * (clients-only departments are forbidden for guests, spec AC4).
 */
class CustomerTicketRequest extends FormRequest
{
    use ValidatesAttachments;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $guest = $this->user() === null || $this->routeIs('support.tickets.*');

        return [
            'department_id' => ['required', 'integer', Rule::exists('support_departments', 'id')],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:65000'],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'service_id' => ['nullable', 'integer', 'min:1'],
            'name' => [$guest ? 'required' : 'nullable', 'string', 'max:255'],
            'email' => [$guest ? 'required' : 'nullable', 'email', 'max:255'],
            /** Honeypot: real people leave it empty. */
            'website' => ['nullable', 'prohibited'],
            ...$this->attachmentRules(staff: false),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...$this->attachmentMessages(), 'website.prohibited' => __('Leave this field empty.')];
    }
}
