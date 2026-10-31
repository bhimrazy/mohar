<?php

namespace App\Modules\Support\Services;

use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Settings\SupportSettings;
use Carbon\CarbonImmutable;

/**
 * Masked public ticket numbers from the support.ticket_number_format setting (spec §4.1).
 * Tokens: %A A-Z, %a a-z, %n 0-9, %y %m %d date parts, %i ticket id.
 */
class TicketNumberGenerator
{
    public const MAX_ATTEMPTS = 10;

    public const MAX_LENGTH = 20;

    public function __construct(private SupportSettings $settings) {}

    /**
     * A number that is not used yet, or null when every attempt collided
     * (the caller then appends "-{id}" once the id is known).
     */
    public function generate(?CarbonImmutable $date = null, ?int $id = null): ?string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $number = $this->render($date ?? CarbonImmutable::now(), $id);

            if (! Ticket::query()->where('number', $number)->exists()) {
                return $number;
            }
        }

        return null;
    }

    public function render(CarbonImmutable $date, ?int $id = null): string
    {
        $format = $this->settings->string('ticket_number_format', '%n%n%n%n%n%n') ?: '%n%n%n%n%n%n';

        $number = (string) preg_replace_callback('/%([Aanymdi])/', fn (array $match): string => match ($match[1]) {
            'A' => chr(random_int(65, 90)),
            'a' => chr(random_int(97, 122)),
            'n' => (string) random_int(0, 9),
            'y' => $date->format('y'),
            'm' => $date->format('m'),
            'd' => $date->format('d'),
            default => $id !== null ? (string) $id : (string) random_int(0, 9),
        }, $format);

        return mb_substr($number, 0, self::MAX_LENGTH);
    }
}
