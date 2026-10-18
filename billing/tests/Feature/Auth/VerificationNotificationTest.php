<?php

namespace Tests\Feature\Auth;

use App\Events\Core\EmailVerificationRequested;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class VerificationNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::emailVerification());
    }

    public function test_sends_verification_notification(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect(route('home'));

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_does_not_send_verification_notification_if_email_is_verified(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect(route('dashboard', absolute: false));

        Notification::assertNothingSent();
    }

    /**
     * Without an EmailVerificationRequested listener Laravel's own VerifyEmail notification is sent directly.
     */
    public function test_verification_uses_laravel_notification_without_a_listener(): void
    {
        Notification::fake();
        Event::forget(EmailVerificationRequested::class);

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post(route('verification.send'));

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
