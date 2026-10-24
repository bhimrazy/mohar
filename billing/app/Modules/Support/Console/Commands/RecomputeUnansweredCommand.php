<?php

namespace App\Modules\Support\Console\Commands;

use App\Modules\Support\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes tickets.first_unanswered_at from the threads of tickets changed since a time. Run it once after a
 * deploy, when the previous release (which does not maintain the column) has stopped, with the deploy's start time.
 * Unlike the migration's backfill it also overwrites non-empty values.
 */
#[Signature('support:recompute-unanswered {--since= : Only tickets updated at or after this time (default: all)} {--batch=1000}')]
#[Description('Recompute the first unanswered customer reply time of tickets changed since a time')]
class RecomputeUnansweredCommand extends Command
{
    public function handle(): int
    {
        $since = $this->option('since') ? CarbonImmutable::parse((string) $this->option('since')) : null;
        $batch = max(1, (int) $this->option('batch'));
        $last = (int) Ticket::query()->max('id');
        $touched = 0;

        for ($from = 1; $from <= $last; $from += $batch) {
            $touched += DB::update(
                "update support_tickets set first_unanswered_at = (
                    select min(m.created_at) from support_ticket_messages m
                    where m.ticket_id = support_tickets.id and m.type = 'reply' and m.author_type in ('client', 'guest')
                    and (support_tickets.last_staff_reply_at is null or m.created_at > support_tickets.last_staff_reply_at)
                ) where id between ? and ? and merged_into_id is null".($since !== null ? ' and updated_at >= ?' : ''),
                $since !== null ? [$from, $from + $batch - 1, $since->format('Y-m-d H:i:s')] : [$from, $from + $batch - 1],
            );
        }

        $this->components->info("Checked {$touched} ticket(s).");

        return self::SUCCESS;
    }
}
