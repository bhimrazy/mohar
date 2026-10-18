<?php

namespace Tests\Feature\Auth;

use App\Contracts\Auth\AuthMailer;
use App\Models\User;
use App\Modules\Core\Support\LaravelAuthMailer;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * With Core's default AuthMailer (no Notifications module) Laravel's own VerifyEmail notification is sent.
     */
    public function test_verification_uses_laravel_notification_with_the_core_auth_mailer(): void
    {
        Notification::fake();
        // Core's default binding, as when the Notifications module is not installed (MODULES=).
        $this->app->instance(AuthMailer::class, new LaravelAuthMailer);

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post(route('verification.send'));

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
