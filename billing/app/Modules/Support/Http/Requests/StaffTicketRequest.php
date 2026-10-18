<?php

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Http\Requests\Concerns\ValidatesAttachments;
use App\Modules\Support\Rules\ServiceOfClient;
use App\Modules\Support\Services\TicketAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Staff opens a ticket on behalf of a client or an email address.
 */
class StaffTicketRequest extends FormRequest
{
    use ValidatesAttachments;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('support.tickets.open') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', Rule::exists('support_departments', 'id')],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'requester_name' => ['required', 'string', 'max:255'],
            'requester_email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:65000'],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'service_id' => ['nullable', 'integer', 'min:1', new ServiceOfClient($this->filled('client_id') ? $this->integer('client_id') : null)],
            'cc' => ['nullable', 'array', 'max:10'],
            'cc.*' => ['email', 'max:255'],
            ...$this->attachmentRules(staff: true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->attachmentMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();

            if ($user !== null && $this->filled('department_id') && ! app(TicketAccess::class)->canAccessDepartment($user, $this->integer('department_id'))) {
                $validator->errors()->add('department_id', __('You do not work in this department.'));
            }
        });
    }
}
