<?php

namespace App\Modules\Domains\Models;

use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Enums\NpStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The staff-run .np registration workflow of one domain (np_manual driver).
 *
 * @property int $id
 * @property int $domain_id
 * @property NpStage $stage
 * @property string|null $applicant_type individual|organisation
 * @property CarbonImmutable $stage_changed_at
 * @property string|null $returned_reasons why staff sent the documents back (shown to the client)
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $rejected_at last registry rejection, cleared when submitted again
 * @property int|null $rejection_code registry rejection cause (1–14)
 * @property string|null $rejection_note
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $registry_expires_on renewal date the registry showed on approval
 * @property CarbonImmutable|null $review_due_on when staff next confirm the status in the registry portal
 * @property CarbonImmutable|null $review_confirmed_at null = a status confirmation is waiting for staff
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Domain $domain
 */
#[Fillable([
    'domain_id', 'stage', 'applicant_type', 'stage_changed_at', 'returned_reasons', 'submitted_at', 'rejected_at',
    'rejection_code', 'rejection_note', 'approved_at', 'registry_expires_on', 'review_due_on', 'review_confirmed_at',
])]
class NpRegistration extends Model
{
    public const APPLICANT_TYPES = ['individual', 'organisation'];

    /** Documents the registry needs per applicant type. */
    public const REQUIRED_DOCUMENTS = [
        'individual' => ['identity'],
        'organisation' => ['registration_certificate', 'request_letter'],
    ];

    protected function casts(): array
    {
        return [
            'stage' => NpStage::class,
            'stage_changed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'rejection_code' => 'integer',
            'approved_at' => 'datetime',
            'registry_expires_on' => 'date',
            'review_due_on' => 'date',
            'review_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class)->withTrashed();
    }

    /**
     * @return HasMany<NpDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(NpDocument::class)->orderBy('id');
    }

    /**
     * Kinds still missing before the documents can go to staff review.
     *
     * @return list<string>
     */
    public function missingDocuments(): array
    {
        $current = $this->documents()->whereNull('delete_after')->pluck('kind')->all();

        return array_values(array_diff(self::REQUIRED_DOCUMENTS[$this->applicant_type] ?? ['identity'], $current));
    }

    /**
     * Requests of a live domain: not deleted, not cancelled, transferred away or fraud.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereHas('domain', fn (Builder $domain) => $domain->withoutTrashed()
            ->whereIn('status', array_map(fn (DomainStatus $status): string => $status->value, DomainStatus::nonTerminal())));
    }

    /**
     * Requests with a step waiting for staff: documents to review, a portal submission or a status to confirm.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWaitingForStaff(Builder $query): void
    {
        $query->open()->where(fn (Builder $query) => $query
            ->whereIn('stage', [NpStage::DocumentsReview->value, NpStage::ReadyToSubmit->value])
            ->orWhere(fn (Builder $query) => $query->where('stage', NpStage::Approved->value)->whereNull('review_confirmed_at')));
    }

    /**
     * Requests the registry has had longer than the staff SLA, or rejections not resubmitted within two business
     * days (the registry drops rejected requests after three to four).
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverdue(Builder $query, CarbonImmutable $submittedBefore, CarbonImmutable $rejectedBefore): void
    {
        $query->open()->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('stage', NpStage::Submitted->value)->where('submitted_at', '<=', $submittedBefore))
            ->orWhere(fn (Builder $query) => $query->whereNotNull('rejected_at')->where('rejected_at', '<=', $rejectedBefore)));
    }
}
