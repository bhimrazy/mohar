<?php

namespace App\Modules\Domains\Http\Requests;

use App\Modules\Core\Support\BusinessCalendar;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\NpWorkflow;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A staff step in a .np request: review the documents, record the portal submission, record the registry's
 * answer, or confirm the status checked in the portal.
 */
class NpStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('domains.np.review') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return match ($this->route('action')) {
            'review' => [
                'approved' => ['required', 'boolean'],
                'checklist' => ['exclude_unless:approved,true', 'required', 'array'],
                ...collect(NpWorkflow::CHECKLIST)->mapWithKeys(fn (string $item): array => ["checklist.{$item}" => $item === 'letter_signed' && ! $this->isOrganisation()
                    ? ['nullable', 'boolean']
                    : ['exclude_unless:approved,true', 'accepted']])->all(),
                'reasons' => ['exclude_if:approved,true', 'required', 'string', 'max:1000'],
            ],
            // "Today" on the company's calendar (staff in Kathmandu are hours ahead of UTC).
            'submitted' => ['submitted_on' => ['required', 'date', 'before_or_equal:'.app(BusinessCalendar::class)->today()->toDateString()]],
            'decision' => [
                'approved' => ['required', 'boolean'],
                'registry_expires_on' => ['exclude_unless:approved,true', 'nullable', 'date', 'after:today'],
                'rejection_code' => ['exclude_if:approved,true', 'nullable', 'integer', 'between:1,14'],
                'note' => ['exclude_if:approved,true', 'required', 'string', 'max:1000'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['checklist.*.accepted' => 'Confirm every check before approving the documents.'];
    }

    private function isOrganisation(): bool
    {
        $domain = $this->route('domain');

        return $domain instanceof Domain && $domain->npRegistration?->applicant_type === 'organisation';
    }
}
