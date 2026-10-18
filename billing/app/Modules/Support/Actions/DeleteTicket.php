<?php

namespace App\Modules\Support\Actions;

use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a ticket with its messages, events and stored attachment files.
 */
class DeleteTicket
{
    public function handle(Ticket $ticket): void
    {
        $files = TicketAttachment::query()->where('ticket_id', $ticket->id)->whereNull('pruned_at')->get(['disk', 'path']);

        DB::transaction(fn () => $ticket->delete());

        foreach ($files as $file) {
            Storage::disk($file->disk)->delete($file->path);
        }
    }
}
