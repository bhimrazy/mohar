<?php

use App\Models\User;
use App\Modules\Core\Models\Role;
use App\Modules\Platform\Models\StaffInvitation;
use App\Modules\Core\Notifications\SecurityNotice;
use App\Modules\Platform\Notifications\StaffInvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;

dataset('hostile names', [
    'script tag' => ["<script>alert('x')</script>"],
    'markdown link' => ['[Click me](javascript:alert(1))'],
    'markdown image' => ['![x](https://evil.test/p.png)'],
]);

test('staff invitation mail escapes the inviter and role names', function (string $hostile) {
    $inviter = User::factory()->staff()->create(['name' => $hostile]);
    $invitation = StaffInvitation::factory()->create([
        'invited_by' => $inviter->id,
        'role_id' => Role::factory()->withPermissions(['support.*'])->create(['name' => $hostile])->id,
    ]);

    $html = (string) (new StaffInvitationNotification($invitation->load('role', 'inviter'), 'token'))
        ->toMail(new AnonymousNotifiable)->render();

    expect($html)->not->toContain('<script>')
        ->not->toContain('href="javascript:')
        ->not->toContain('<img src="https://evil.test')
        ->not->toMatch('/<a [^>]*>Click me<\/a>/');
})->with('hostile names');

test('security notices escape interpolated user values', function (string $hostile) {
    $html = (string) (new SecurityNotice('Your sign-in email was changed', [__(':actor changed your email.', ['actor' => $hostile])]))
        ->toMail(new AnonymousNotifiable)->render();

    expect($html)->not->toContain('<script>')
        ->not->toContain('href="javascript:')
        ->not->toContain('<img src="https://evil.test')
        ->toContain('changed your email');
})->with('hostile names');
