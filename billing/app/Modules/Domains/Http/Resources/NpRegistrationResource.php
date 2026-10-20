<?php

namespace App\Modules\Domains\Http\Resources;

use App\Modules\Domains\Enums\NpStage;
use App\Modules\Domains\Models\NpDocument;
use App\Modules\Domains\Models\NpRegistration;
use App\Modules\Domains\Services\NpWorkflow;
use App\Support\Currencies\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

/**
 * A .np request for the domain pages. Staff get the workflow dates and links that open the documents (through a fresh signed link); clients
 * get the step, what is missing and the reasons a step went back, never document links.
 *
 * @mixin NpRegistration
 */
class NpRegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $staff = (bool) $user?->is_staff;
        $canOpen = $user !== null && $staff && $user->hasPermission('domains.np.review');
        $workflow = app(NpWorkflow::class);

        return [
            'stage' => $this->stage->value,
            'applicant_type' => $this->applicant_type,
            'missing' => $this->missingDocuments(),
            'returned_reasons' => $this->returned_reasons,
            'rejected' => $this->rejected_at !== null,
            'rejection_note' => $this->rejection_note,
            'rejection_code' => $this->rejection_code,
            'documents' => $this->documents->map(fn (NpDocument $document): array => [
                'id' => $document->id,
                'kind' => $document->kind,
                'name' => $document->original_name,
                'size' => $document->size,
                'uploaded_at' => $document->created_at?->toIso8601String(),
                'current' => $document->delete_after === null,
                'delete_after' => $document->delete_after?->toDateString(),
                'url' => $canOpen ? route('admin.domains.np.document.open', ['domain' => $this->domain_id, 'document' => $document->id]) : null,
            ])->values()->all(),
            'handling_fee_note' => $workflow->option('handling_fee_note'),
            'abandoned' => $workflow->queue('abandoned')->whereKey($this->id)->exists(),
            ...$staff ? [
                'stage_changed_at' => $this->stage_changed_at->toIso8601String(),
                'submitted_at' => $this->submitted_at?->toIso8601String(),
                'rejected_at' => $this->rejected_at?->toIso8601String(),
                'approved_at' => $this->approved_at?->toIso8601String(),
                'registry_expires_on' => $this->registry_expires_on?->toDateString(),
                'review_due_on' => $this->review_due_on?->toDateString(),
                'review_pending' => $this->stage === NpStage::Approved && $this->review_confirmed_at === null,
                'overdue' => $workflow->queue('overdue')->whereKey($this->id)->exists(),
                'hostmaster_email' => $workflow->option('hostmaster_email'),
                'paid' => $this->domain->first_payment_amount > 0 ? app(MoneyFormatter::class)->amount($this->domain->first_payment_amount, $this->domain->currency) : null,
                'invoice_url' => $this->domain->provision_invoice_id !== null && Route::has('admin.billing.invoices.show') ? route('admin.billing.invoices.show', $this->domain->provision_invoice_id) : null,
            ] : [],
        ];
    }
}
