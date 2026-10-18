<?php

namespace App\Modules\Support\Enums;

/**
 * Ticket workflow statuses (spec §3). Which statuses count as "awaiting reply" or auto-close
 * is configurable through the support.awaiting_reply_statuses / support.auto_close_statuses settings.
 */
enum TicketStatus: string
{
    case Open = 'open';
    case Answered = 'answered';
    case CustomerReply = 'customer_reply';
    case OnHold = 'on_hold';
    case InProgress = 'in_progress';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Answered => 'Answered',
            self::CustomerReply => 'Customer reply',
            self::OnHold => 'On hold',
            self::InProgress => 'In progress',
            self::Closed => 'Closed',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }

    /**
     * Statuses that are still "active" (everything except closed).
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => ! $status->isClosed()));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
