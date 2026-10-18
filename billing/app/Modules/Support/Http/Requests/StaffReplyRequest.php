<?php

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Http\Requests\Concerns\ValidatesAttachments;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff reply or note (notes ignore status).
 */
class StaffReplyRequest extends FormRequest
{
    use ValidatesAttachments;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('support.tickets.reply') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:65000'],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'version' => ['nullable', 'integer'],
            'force' => ['nullable', 'boolean'],
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
}
