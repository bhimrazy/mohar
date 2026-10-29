<?php

namespace App\Modules\Core\Support;

/**
 * How long invitation links work, for staff and client-login invitations alike (platform.invitation_expiry_days),
 * and what the template previews show.
 */
final class InvitationExpiry
{
    public static function days(): int
    {
        return min(30, max(1, (int) app(Settings::class)->get('platform.invitation_expiry_days', 7)));
    }

    /**
     * "7 days", in the reader's language.
     */
    public static function phrase(): string
    {
        return trans_choice(':count day|:count days', self::days(), ['count' => self::days()]);
    }
}
