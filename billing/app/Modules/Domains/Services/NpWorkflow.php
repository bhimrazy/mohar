<?php

namespace App\Modules\Domains\Services;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\BusinessCalendar;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Actions\SyncDomain;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Enums\NpStage;
use App\Modules\Domains\Exceptions\DomainActionNotAllowed;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\NpDocument;
use App\Modules\Domains\Models\NpRegistration;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Support\Currencies\MoneyFormatter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The .np registration workflow (registrars.md §9). The registry has no API: the client uploads documents, staff
 * check them, submit the request in the registry portal and record the answer here. Every step locks the request,
 * checks its stage and writes the activity log; a step that hands work to staff starts with "Staff task:" and shows
 * in the admin to-dos.
 */
class NpWorkflow
{
    public const DRIVER = 'np_manual';

    /** What staff confirm before submitting. `letter_signed` applies to organisations only. */
    public const CHECKLIST = ['name_matches', 'extension_fits', 'readable', 'letter_signed', 'nameservers_valid', 'owner_contact'];

    /** Business days a rejected request may wait: the registry drops rejected requests after three to four. */
    public const RESUBMIT_BUSINESS_DAYS = 2;

    /** Days without a client upload or a staff step after which a request waiting for documents is listed as abandoned. */
    public const ABANDONED_DAYS = 90;

    public function __construct(
        private DomainStateMachine $states,
        private ActivityLogger $activity,
        private Settings $settings,
        private SyncDomain $sync,
        private BusinessCalendar $calendar,
    ) {}

    public function start(Domain $domain): NpRegistration
    {
        $registration = NpRegistration::query()->firstOrCreate(['domain_id' => $domain->id], ['stage' => NpStage::AwaitingDocuments, 'stage_changed_at' => now()]);

        if ($registration->wasRecentlyCreated) {
            $this->activity->log('.np registration request opened; waiting for the registrant\'s documents', $domain, $domain->client_id);
        }

        return $registration;
    }

    /**
     * Store a document encrypted. A new applicant type starts the set again; a new file replaces the current one of
     * its kind, and the replaced file is deleted at once.
     */
    public function upload(NpRegistration $registration, string $applicantType, string $kind, UploadedFile $file, ?int $userId): NpDocument
    {
        return $this->step($registration, [NpStage::AwaitingDocuments], function (NpRegistration $locked) use ($applicantType, $kind, $file, $userId): NpDocument {
            $replaced = $locked->documents()->whereNull('delete_after');

            if ($locked->applicant_type === $applicantType) {
                $replaced->where('kind', $kind);
            }

            $this->delete($replaced->get());
            $locked->forceFill(['applicant_type' => $applicantType])->save();
            $path = 'np-documents/'.$locked->id.'/'.Str::uuid()->toString();
            Storage::disk(NpDocument::DISK)->put($path, Crypt::encryptString((string) $file->get()));
            $this->log($locked, '.np document uploaded', ['kind' => $kind]);

            return $locked->documents()->create([
                'kind' => $kind,
                'path' => $path,
                'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
                'mime' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'uploaded_by' => $userId,
            ]);
        });
    }

    public function sendForReview(NpRegistration $registration): void
    {
        $this->step($registration, [NpStage::AwaitingDocuments], function (NpRegistration $locked): void {
            if ($locked->missingDocuments() !== []) {
                throw new DomainActionNotAllowed('Upload every required document before sending them for review.');
            }

            $this->move($locked, NpStage::DocumentsReview, 'Staff task: review the .np registration documents', ['returned_reasons' => null]);
        });
    }

    /**
     * @param  list<string>  $checklist  the checks staff confirmed (kept in the activity log)
     */
    public function review(NpRegistration $registration, bool $approved, ?string $reasons, array $checklist = []): void
    {
        $this->step($registration, [NpStage::DocumentsReview], fn (NpRegistration $locked) => $approved
            ? $this->move($locked, NpStage::ReadyToSubmit, 'Staff task: submit the .np request in the registry portal', [], ['checklist' => $checklist])
            : $this->move($locked, NpStage::AwaitingDocuments, '.np documents returned to the client', ['returned_reasons' => $reasons]));
    }

    public function markSubmitted(NpRegistration $registration, CarbonImmutable $on): void
    {
        $this->step($registration, [NpStage::ReadyToSubmit], function (NpRegistration $locked) use ($on): void {
            $this->move($locked, NpStage::Submitted, '.np request submitted in the registry portal', ['submitted_at' => $on, 'rejected_at' => null]);

            if ($locked->domain->status === DomainStatus::Pending) {
                $this->states->transition($locked->domain, DomainStatus::PendingRegistration, 'Resubmitted to the .np registry', ['last_error' => null]);
            }
        });
    }

    /**
     * Record the registry's answer. Approval activates the domain through the normal sync path (the driver's sync
     * now reports it active); a rejection returns the domain to pending for a resubmission. Either way the
     * documents are kept for the retention period only.
     *
     * @return string|null why activating the domain failed (the hourly sync retries it)
     */
    public function decide(NpRegistration $registration, bool $approved, ?CarbonImmutable $registryExpiry, ?int $code, ?string $note, ?int $staffId): ?string
    {
        $this->step($registration, [NpStage::Submitted], function (NpRegistration $locked) use ($approved, $registryExpiry, $code, $note): void {
            $locked->documents()->whereNull('delete_after')->update(['delete_after' => $this->calendar->today()->addDays($this->retentionDays())]);

            if ($approved) {
                $this->move($locked, NpStage::Approved, '.np request approved by the registry', [
                    'approved_at' => now(),
                    'registry_expires_on' => $registryExpiry,
                    'review_due_on' => $registryExpiry ?? $this->calendar->today()->addYearsNoOverflow($this->reviewCycleYears()),
                    'review_confirmed_at' => now(),
                ]);

                return;
            }

            $this->move($locked, NpStage::AwaitingDocuments, 'Staff task: fix and resubmit the rejected .np request within two business days', ['rejected_at' => now(), 'rejection_code' => $code, 'rejection_note' => $note]);
            $this->states->transition($locked->domain, DomainStatus::Pending, 'Rejected by the .np registry', ['last_error' => $note]);
        });

        if (! $approved) {
            return null;
        }

        $domain = $registration->domain()->firstOrFail();
        $error = $this->sync->handle($domain, staffId: $staffId)['error'];

        return $error ?? ($domain->fresh()?->status === DomainStatus::Active ? null : "The approval is recorded, but the domain is {$domain->fresh()?->status->label()}, so it was not activated.");
    }

    /**
     * Staff checked the status (and any reverification) in the registry portal.
     */
    public function confirmReview(NpRegistration $registration): void
    {
        $this->step($registration, [NpStage::Approved], function (NpRegistration $locked): void {
            $locked->forceFill(['review_confirmed_at' => now()])->save();
            $locked->domain->forceFill(['needs_review' => false, 'last_error' => null])->save();
            $this->log($locked, '.np registry status confirmed');
        });
    }

    /**
     * A renewal: the registry charges nothing, so it moves the next status check and asks staff to confirm the
     * status in the portal.
     */
    public function renewed(Domain $domain): void
    {
        $this->step(NpRegistration::query()->where('domain_id', $domain->id)->firstOrFail(), [NpStage::Approved], function (NpRegistration $locked): void {
            $today = $this->calendar->today();
            $from = $locked->review_due_on?->gt($today) ? $locked->review_due_on : $today;
            $locked->forceFill(['review_due_on' => $from->addYearsNoOverflow($this->reviewCycleYears()), 'review_confirmed_at' => null])->save();
            $this->log($locked, 'Staff task: confirm the .np registry status after the renewal');
        });
    }

    /**
     * Daily: flag domains whose status check date passed without staff confirming it.
     */
    public function flagOverdueReviews(CarbonImmutable $today): int
    {
        $due = NpRegistration::query()->where('stage', NpStage::Approved->value)->whereNull('review_confirmed_at')
            ->whereDate('review_due_on', '<', $today)->with('domain')->orderBy('id')->get()
            ->reject(fn (NpRegistration $registration): bool => $registration->domain->needs_review || $registration->domain->trashed());

        foreach ($due as $registration) {
            $registration->domain->forceFill(['needs_review' => true, 'last_error' => 'The .np status check date passed; confirm the status in the registry portal.'])->save();
            $this->log($registration, 'Staff task: the .np status check date passed without confirmation');
        }

        return $due->count();
    }

    /**
     * Daily: start the retention period of documents whose domain was closed or deleted (by staff or any other
     * path), then delete documents past their retention date, one activity entry per request. Each document is
     * deleted on its own: one failure is logged and skipped, and never stops the rest.
     */
    public function pruneDocuments(CarbonImmutable $today): int
    {
        $deleteAfter = $today->addDays($this->retentionDays())->toDateString();
        NpDocument::query()->whereNull('delete_after')
            ->whereIn('np_registration_id', NpRegistration::query()->whereNotIn('id', NpRegistration::query()->open()->select('id'))->select('id'))
            ->update(['delete_after' => $deleteAfter]);

        $deleted = [];

        foreach (NpDocument::query()->whereDate('delete_after', '<=', $today)->with('registration.domain')->orderBy('id')->get() as $document) {
            try {
                $this->delete(collect([$document]));
                $deleted[$document->np_registration_id][] = $document;
            } catch (Throwable $exception) {
                Log::warning('A .np document past its retention date could not be deleted; retrying tomorrow.', ['document_id' => $document->id, 'error' => $exception->getMessage()]);
            }
        }

        foreach ($deleted as $documents) {
            try {
                $this->log($documents[0]->registration, '.np documents deleted after the retention period', ['count' => count($documents)]);
            } catch (Throwable $exception) {
                Log::warning('Logging a .np document deletion failed.', ['document_id' => $documents[0]->id, 'error' => $exception->getMessage()]);
            }
        }

        return array_sum(array_map('count', $deleted));
    }

    /**
     * Staff decided to keep waiting for an abandoned request's documents: the 90-day clock starts again.
     */
    public function extend(NpRegistration $registration): void
    {
        $this->step($registration, [NpStage::AwaitingDocuments], function (NpRegistration $locked): void {
            $locked->forceFill(['stage_changed_at' => now()])->save();
            $this->log($locked, '.np request kept open: staff are waiting for the documents');
        });
    }

    /**
     * What staff need to decide on an abandoned request: the domain, the client and what was paid.
     */
    public function abandonedSummary(NpRegistration $registration): string
    {
        $domain = $registration->domain;
        $paid = $domain->first_payment_amount > 0 ? app(MoneyFormatter::class)->amount($domain->first_payment_amount, $domain->currency).' paid' : 'nothing paid';

        return "{$domain->name_unicode} for {$domain->client->display_name}, {$paid}".($domain->provision_invoice_id !== null ? ' on its order invoice' : '');
    }

    /**
     * Requests waiting for staff; overdue at the registry (submitted longer than the SLA, or rejected and not
     * resubmitted within two business days); or abandoned (still waiting for the client's documents, with no upload
     * and no staff step for ABANDONED_DAYS). Abandoned requests are only listed: staff decide, nothing is cancelled.
     *
     * @param  'waiting'|'overdue'|'abandoned'  $queue
     * @return Builder<NpRegistration>
     */
    public function queue(string $queue): Builder
    {
        // Business days are counted on the company's calendar, then compared with UTC timestamps.
        $now = CarbonImmutable::now($this->calendar->timezone());

        return match ($queue) {
            'overdue' => NpRegistration::query()->overdue(self::businessDaysBefore($now, max(1, (int) ($this->option('staff_sla_business_days') ?? 4)))->utc(), self::businessDaysBefore($now, self::RESUBMIT_BUSINESS_DAYS)->utc()),
            'abandoned' => NpRegistration::query()->abandoned($this->calendar->startOfDayUtc($this->calendar->today()->subDays(self::ABANDONED_DAYS))),
            default => NpRegistration::query()->waitingForStaff(),
        };
    }

    /**
     * The date $days business days before $from. Saturdays, Nepal's weekly holiday, are skipped; public holidays
     * are not known here.
     */
    public static function businessDaysBefore(CarbonImmutable $from, int $days): CarbonImmutable
    {
        while ($days > 0) {
            $from = $from->subDay();
            $days -= $from->isSaturday() ? 0 : 1;
        }

        return $from;
    }

    /**
     * A setting of the np_manual registrar account (Admin → Registrars).
     */
    public function option(string $key): mixed
    {
        return RegistrarAccount::query()->where('key', self::DRIVER)->first()?->settings[$key] ?? null;
    }

    private function reviewCycleYears(): int
    {
        return min(10, max(1, (int) ($this->option('review_cycle_years') ?? 1)));
    }

    private function retentionDays(): int
    {
        return max(1, (int) $this->settings->get('domains.np_document_retention_days', 30));
    }

    /**
     * @template T
     *
     * @param  list<NpStage>  $stages
     * @param  Closure(NpRegistration): T  $step
     * @return T
     */
    private function step(NpRegistration $registration, array $stages, Closure $step): mixed
    {
        return DB::transaction(function () use ($registration, $stages, $step): mixed {
            $locked = NpRegistration::query()->with('domain')->lockForUpdate()->findOrFail($registration->id);

            if ($locked->domain->trashed() || $locked->domain->status->isTerminal()) {
                throw new DomainActionNotAllowed("The domain is {$locked->domain->status->label()}, so its .np request is closed.");
            }

            if (! in_array($locked->stage, $stages, true)) {
                throw new DomainActionNotAllowed('This .np request has moved on to another step; reload the page.');
            }

            $result = $step($locked);
            $registration->setRawAttributes($locked->getAttributes(), true);

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $properties
     */
    private function move(NpRegistration $registration, NpStage $stage, string $description, array $attributes = [], array $properties = []): void
    {
        $registration->forceFill([...$attributes, 'stage' => $stage, 'stage_changed_at' => now()])->save();
        $this->log($registration, $description, array_filter([...$properties, 'reasons' => $attributes['returned_reasons'] ?? null, 'code' => $attributes['rejection_code'] ?? null, 'note' => $attributes['rejection_note'] ?? null]));
    }

    /**
     * @param  Collection<int, NpDocument>  $documents
     */
    private function delete(Collection $documents): void
    {
        foreach ($documents as $document) {
            Storage::disk(NpDocument::DISK)->delete($document->path);
            $document->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(NpRegistration $registration, string $description, array $properties = []): void
    {
        $this->activity->log($description, $registration->domain, $registration->domain->client_id, $properties);
    }
}
