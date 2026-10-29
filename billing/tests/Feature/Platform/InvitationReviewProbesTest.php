<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Modules\Platform\Actions\Clients\ManageClientLogins;
use App\Modules\Platform\Actions\Clients\MergeClients;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Review-board probes for PR #78 (client invitation tokens). Each one reproduces a finding; the engineer adopts them.
 */

beforeEach(function () {
    app(TemplateCatalog::class)->install('en');
    Mail::fake();
});

function probeInvite(Client $client, string $email = 'helper@example.test'): string
{
    app(ManageClientLogins::class)->invite($client, $email, 'Hari Helper', ['billing']);

    $token = '';
    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($email, &$token): bool {
        if ($mail->hasTo($email) && preg_match('#/account-invitation/([A-Za-z0-9]+)#', $mail->plainText, $match)) {
            $token = $match[1];
        }

        return true;
    });

    return $token;
}

it('indexes every foreign key of client_invitations (architecture.md: indexes on foreign keys)', function () {
    expect(Schema::hasIndex('client_invitations', ['user_id']))->toBeTrue()
        ->and(Schema::hasIndex('client_invitations', ['invited_by']))->toBeTrue();
});

it('refuses a merge while a login has a pending invitation, and moves the login once it accepted', function () {
    $source = Client::factory()->withOwner()->create(['company_name' => 'Source Ltd']);
    $target = Client::factory()->withOwner()->create(['company_name' => 'Target Ltd']);
    $token = probeInvite($source);
    $helper = User::query()->where('email', 'helper@example.test')->sole();

    // Unverified until it accepts (#520, M2): staff wait for acceptance or remove the login.
    expect(fn () => app(MergeClients::class)->handle($source, $target->fresh()))->toThrow(ValidationException::class, 'helper@example.test');
    expect(ClientInvitation::query()->where('user_id', $helper->id)->sole()->client_id)->toBe($source->id);

    $this->post(route('platform.client-invitations.accept', $token), ['password' => 'Invite-Passw0rd!x', 'password_confirmation' => 'Invite-Passw0rd!x'])->assertSessionHasNoErrors();
    auth()->logout();
    app(MergeClients::class)->handle($source->fresh(), $target->fresh());

    expect($target->users()->whereKey($helper->id)->exists())->toBeTrue();
});

it('stops showing a login as invited, and voids its link, once it set a password through "Forgot your password?"', function () {
    $client = Client::factory()->withOwner()->create();
    $token = probeInvite($client);
    $helper = User::query()->where('email', 'helper@example.test')->sole();

    $reset = Password::broker()->createToken($helper);
    $this->post(route('password.update'), ['token' => $reset, 'email' => $helper->email, 'password' => 'a-Long-passw0rd!', 'password_confirmation' => 'a-Long-passw0rd!'])
        ->assertSessionHasNoErrors();
    auth()->logout();

    // The invitation link would otherwise overwrite the password the customer just chose.
    $this->get(route('platform.client-invitations.show', $token))
        ->assertInertia(fn (Assert $page) => $page->whereNot('state', 'valid'));
});
