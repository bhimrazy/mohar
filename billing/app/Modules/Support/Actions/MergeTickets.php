<?php

namespace App\Modules\Support\Actions;

use App\Events\Support\TicketMerged;
use App\Models\User;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\ClosedBy;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketAttachment;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\TicketRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Merge newer tickets into the oldest (spec §4.4): messages move to the target and interleave
 * chronologically, the target takes the status of the ticket with the latest message, sources keep
 * merged_into_id (their URLs and email replies route to the target).
 *
 * Tickets of different requesters (client accounts, or different guest emails) merge only with the
 * cross-client permission, and their requesters are then NOT added to the target: access to the
 * merged ticket is never granted through a source ticket's link or email address.
 */
class MergeTickets
{
    public function __construct(private TicketRecorder $recorder) {}

    /**
     * @param  list<int>  $ticketIds
     *
     * @throws ValidationException
     */
    public function handle(array $ticketIds, User $staff, bool $allowCrossClient = false): Ticket
    {
        $actor = TicketAuthor::staff($staff);

        $target = DB::transaction(function () use ($ticketIds, $actor, $allowCrossClient): Ticket {
            $tickets = Ticket::query()->whereIn('id', $ticketIds)->whereNull('merged_into_id')
                ->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();

            if ($tickets->count() < 2) {
                throw ValidationException::withMessages(['tickets' => __('Select at least two tickets to merge.')]);
            }

            if (! $allowCrossClient && $tickets->map(fn (Ticket $ticket): string => $ticket->requesterIdentity())->unique()->count() > 1) {
                throw ValidationException::withMessages(['tickets' => __('Only tickets of the same client can be merged.')]);
            }

            /** @var Ticket $target */
            $target = $tickets->first();
            $sources = $tickets->slice(1)->values();
            $sourceIds = array_values($sources->pluck('id')->all());

            $latest = TicketMessage::query()->whereIn('ticket_id', $tickets->pluck('id'))->where('type', 'reply')
                ->orderByDesc('created_at')->orderByDesc('id')->first();
            $statusTicket = $latest !== null ? $tickets->firstWhere('id', $latest->ticket_id) : $target;

            TicketMessage::query()->whereIn('ticket_id', $sourceIds)->update(['ticket_id' => $target->id]);
            TicketAttachment::query()->whereIn('ticket_id', $sourceIds)->update(['ticket_id' => $target->id]);

            $previousStatus = $target->status;
            $target->status = $statusTicket instanceof Ticket ? $statusTicket->status : $target->status;
            $target->last_reply_at = $tickets->max('last_reply_at');
            $target->last_client_reply_at = $tickets->max('last_client_reply_at');
            $target->last_staff_reply_at = $tickets->max('last_staff_reply_at');
            $target->first_staff_reply_at = $tickets->min('first_staff_reply_at');
            $target->cc = $this->mergedCc($target, $tickets);

            if (! $target->isDirty(Ticket::VERSIONED)) {
                $target->version = $target->version + 1;
            }

            $target->save();

            if ($previousStatus !== $target->status) {
                $this->recorder->record($target, $actor, 'status', $previousStatus->value, $target->status->value, ['reason' => 'merge']);
            }

            foreach ($sources as $source) {
                $source->forceFill([
                    // Rotate the key: the old guest link only resolves to the "merged" page from now on.
                    'merged_access_key_hash' => hash('sha256', $source->access_key),
                    'access_key' => Str::random(32),
                    'merged_into_id' => $target->id,
                    'status' => TicketStatus::Closed,
                    'closed_at' => $source->closed_at ?? now(),
                    'closed_by' => $source->closed_by ?? ClosedBy::Staff,
                ])->save();
                $this->recorder->record($source, $actor, 'merged_into', null, $target->number);
            }

            $this->recorder->record($target, $actor, 'merged', implode(', ', $sources->pluck('number')->all()), $target->number, ['source_ids' => $sourceIds, 'label' => '[merged]']);

            return $target;
        });

        TicketMerged::dispatch($target->id, array_values(array_map('intval', array_diff($ticketIds, [$target->id]))));

        return $target;
    }

    /**
     * CC of every ticket, plus the requesters of same-requester sources (other logins/contacts of the
     * same client) so their email replies keep reaching the merged ticket.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return list<string>
     */
    private function mergedCc(Ticket $target, Collection $tickets): array
    {
        $cc = [];

        foreach ($tickets as $ticket) {
            $cc = [...$cc, ...($ticket->cc ?? [])];

            if ($ticket->id !== $target->id && $ticket->requesterIdentity() === $target->requesterIdentity()) {
                $cc[] = mb_strtolower($ticket->requester_email);
            }
        }

        return array_slice(array_values(array_diff(array_unique($cc), [mb_strtolower($target->requester_email)])), 0, 10);
    }
}
