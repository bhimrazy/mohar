<?php

use App\Contracts\Notifications\MergeFieldRegistry;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Contact;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\TemplateCatalog;
use Illuminate\Support\Facades\Mail;

/*
 * Shared helpers for the Notifications feature tests (required by each test file).
 */

function seedEmailTemplates(): void
{
    app(TemplateCatalog::class)->install('en');
}

/**
 * Replaces the owning modules' merge-field resolvers with fixed data, so tests do not depend on them.
 *
 * @param  array<string, array<string, mixed>>  $entities  entity => fields
 */
function fakeMergeFields(array $entities): void
{
    foreach ($entities as $entity => $fields) {
        app(MergeFieldRegistry::class)->register($entity, fn (int $id): array => [...$fields, 'id' => $fields['id'] ?? $id]);
    }
}

/**
 * @param  array<string, bool>  $preferences
 */
function contactFor(Client $client, string $email, array $preferences): Contact
{
    return Contact::factory()->for($client)->create(['email' => $email, 'email_preferences' => $preferences]);
}

function fullAdministrator(string $email = 'admin@example.test'): User
{
    return User::factory()->staff(['*'])->create(['email' => $email]);
}

/**
 * @return list<string> recipients of the templated mails sent with the given subject fragment
 */
function mailedTo(?string $subjectContains = null): array
{
    $recipients = [];

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use (&$recipients, $subjectContains): bool {
        if ($subjectContains === null || str_contains($mail->subjectLine, $subjectContains)) {
            foreach ($mail->to as $to) {
                $recipients[] = $to['address'];
            }
        }

        return true;
    });

    sort($recipients);

    return $recipients;
}

/**
 * @return list<string>
 */
function loggedRecipients(string $templateKey): array
{
    return EmailMessage::query()->where('template_key', $templateKey)->orderBy('recipient')->pluck('recipient')->all();
}
