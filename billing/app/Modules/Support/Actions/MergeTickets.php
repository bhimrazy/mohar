<?php

namespace App\Modules\Support\Actions;

use App\Models\User;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Enums\ClosedBy;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Events\TicketMerged;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketAttachment;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\TicketRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merge newer tickets into the oldest (spec §4.4): messages move to the target and interleave
 * chronologically, the target takes the status of the ticket with the latest message, sources keep
 * merged_into_id (their URLs and email replies route to the target).
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

            if (! $allowCrossClient && $tickets->pluck('client_id')->unique()->count() > 1) {
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

            $target->status = $statusTicket instanceof Ticket ? $statusTicket->status : $target->status;
            $target->last_reply_at = $tickets->max('last_reply_at');
            $target->last_client_reply_at = $tickets->max('last_client_reply_at');
            $target->last_staff_reply_at = $tickets->max('last_staff_reply_at');
            $target->first_staff_reply_at = $tickets->min('first_staff_reply_at');
            $target->cc = array_values(array_unique(array_merge(...$tickets->map(fn (Ticket $ticket): array => $ticket->cc ?? [])->all())));
            $target->version = $target->version + 1;
            $target->save();

            foreach ($sources as $source) {
                $source->forceFill([
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
}
