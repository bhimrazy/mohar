<?php

namespace App\Modules\Core\Support;

use App\Contracts\Auth\AuthMailer;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;

/**
 * Default AuthMailer: Laravel's own notifications (branded through the shared mail layout), rendered with every
 * link rooted at APP_URL.
 */
class LaravelAuthMailer implements AuthMailer
{
    public function sendPasswordReset(User $user, #[\SensitiveParameter] string $token): void
    {
        AccountLinks::withAppOrigin(fn () => $user->notify(new ResetPassword($token)));
    }

    public function sendEmailVerification(User $user): void
    {
        AccountLinks::withAppOrigin(fn () => $user->notify(new VerifyEmail));
    }
}
