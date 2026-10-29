<?php

use App\Contracts\Notifications\SystemMailer;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\NullSystemMailer;
use App\Modules\Platform\Actions\Clients\ManageClientLogins;
use App\Modules\Platform\Actions\Clients\MergeClients;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * Second re-review of #520. The merge exemption ("an invited login nobody has used moves with its invitation") assumes
 * nobody knows the password of a login with no sign-in. A self-registered login whose sign-up did not reach the
 * sign-in step (any failure after CreateNewUser commits, e.g. the verification mail fallback throwing) has a password
 * its holder chose, no last_login_at, and passes neverUsed(), so staff may invite it and a merge then moves it.
 */

beforeEach(function () {
    Currency::factory()->create();
    app()->instance(SystemMailer::class, new NullSystemMailer);
});

test('R3: a self-registered login whose sign-up did not sign it in is moved by a merge once staff invite it', function () {
    $corp = Client::factory()->withOwner()->create(['company_name' => 'Corp']);

    // The squatter signs up with Corp's address; something after the commit fails, so Fortify never logs them in.
    Event::listen(Registered::class, fn () => throw new RuntimeException('mail transport down'));
    try {
        $this->post(route('register'), ['name' => 'Corp Accounts', 'email' => 'accounts@corp.example', 'password' => 'Squatter-chosen-pass-1', 'password_confirmation' => 'Squatter-chosen-pass-1']);
    } catch (RuntimeException) {
    }
    $this->assertGuest();

    $squatter = User::query()->where('email', 'accounts@corp.example')->sole();
    $duplicate = $squatter->clients()->sole();
    expect($squatter->last_login_at)->toBeNull()
        ->and($squatter->email_verified_at)->toBeNull()
        ->and(ClientInvitation::invitableWithoutOne($squatter))->toBeTrue();

    // Staff tidy up: the page offers "Send invitation" for a login nobody has used; the mail goes to Corp's mailbox.
    Notification::fake();
    app(ManageClientLogins::class)->resendInvitation($duplicate, $squatter);
    expect(ClientInvitation::query()->where('user_id', $squatter->id)->exists())->toBeTrue();

    try {
        app(MergeClients::class)->handle($duplicate->fresh(), $corp);
    } catch (ValidationException) {
        // Refusing is the safe outcome.
    }

    expect(DB::table('client_user')->where('client_id', $corp->id)->where('user_id', $squatter->id)->exists())->toBeFalse();
});

test('R4: after the merge, the squatter signing in with the password they chose has no access to the target', function () {
    $corp = Client::factory()->withOwner()->create(['company_name' => 'Corp']);
    Event::listen(Registered::class, fn () => throw new RuntimeException('mail transport down'));
    try {
        $this->post(route('register'), ['name' => 'Corp Accounts', 'email' => 'accounts@corp.example', 'password' => 'Squatter-chosen-pass-1', 'password_confirmation' => 'Squatter-chosen-pass-1']);
    } catch (RuntimeException) {
    }
    $squatter = User::query()->where('email', 'accounts@corp.example')->sole();
    $duplicate = $squatter->clients()->sole();
    Notification::fake();
    app(ManageClientLogins::class)->resendInvitation($duplicate, $squatter);

    try {
        app(MergeClients::class)->handle($duplicate->fresh(), $corp);
    } catch (ValidationException) {
    }

    $this->post(route('login.store'), ['email' => 'accounts@corp.example', 'password' => 'Squatter-chosen-pass-1']);
    $this->assertAuthenticatedAs($squatter);
    expect(DB::table('client_user')->where('client_id', $corp->id)->where('user_id', $squatter->id)->value('permissions'))->toBeNull();
});

