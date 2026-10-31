<?php

namespace App\Modules\Support\Actions;

use App\Events\Support\TicketReplyRated;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Settings\SupportSettings;
use Illuminate\Validation\ValidationException;

/**
 * Client/guest rating of a staff reply, 1-5, changeable (spec §4.2).
 */
class RateReply
{
    public function __construct(private SupportSettings $settings) {}

    public function handle(TicketMessage $message, int $rating): TicketMessage
    {
        if (! $this->settings->bool('reply_ratings_enabled', true)) {
            throw ValidationException::withMessages(['rating' => __('Reply ratings are turned off.')]);
        }

        if (! $message->isStaffReply()) {
            throw ValidationException::withMessages(['rating' => __('Only replies from our team can be rated.')]);
        }

        $message->forceFill(['rating' => max(1, min(5, $rating))])->save();
        TicketReplyRated::dispatch($message->ticket_id, $message->id, (int) $message->rating);

        return $message;
    }
}
