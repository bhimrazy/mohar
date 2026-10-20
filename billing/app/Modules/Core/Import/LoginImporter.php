<?php

namespace App\Modules\Core\Import;

use App\Contracts\Migration\RecordImporter;
use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportIssue;
use App\Data\Migration\ImportOutcome;
use App\Data\Migration\ImportRecord;
use App\Enums\ImportAction;
use App\Enums\ImportSeverity;
use App\Models\User;
use App\Modules\Core\Models\Client;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The owner login of an imported client (`user`, client-import spec §6.11). Attributes: `email`, `name`,
 * `verified`; link `client_id`. A new login gets the hash of a random secret nobody keeps (one per importer instance,
 * so a chunk hashes once), so no password signs in until the invitation sets one. A staff login is never linked; an
 * existing client login with the address becomes the owner.
 */
class LoginImporter implements RecordImporter
{
    private ?string $unusablePassword = null;

    public function key(): string
    {
        return 'user';
    }

    public function rules(): array
    {
        return ['email' => ['required', 'email', 'max:255'], 'name' => ['required', 'string', 'max:255']];
    }

    public function validate(ImportRecord $record, ImportContext $context): array
    {
        return [];
    }

    public function upsert(ImportRecord $record, ImportContext $context, ?int $existingTargetId): ImportOutcome
    {
        $client = Client::query()->findOrFail($record->links['client_id'] ?? 0);
        $email = mb_strtolower((string) $record->attributes['email']);
        $user = User::query()->where('email', $email)->first();

        if ($user?->is_staff) {
            return new ImportOutcome(null, ImportAction::Skipped, [new ImportIssue(ImportSeverity::Warning, 'staff_login', __('This email belongs to a staff login, so no login was created for the client.'))]);
        }

        $created = $user === null;

        if ($context->dryRun) {
            return new ImportOutcome($user?->id, $created ? ImportAction::Created : ImportAction::Updated);
        }

        if ($user === null) {
            $user = new User(['name' => mb_substr((string) $record->attributes['name'], 0, 255), 'email' => $email]);
            $user->forceFill([
                'password' => $this->unusablePassword ??= Hash::make(Str::random(64)),
                'email_verified_at' => ($record->attributes['verified'] ?? false) === true ? $context->importedAt : null,
            ])->save();
        }

        if (! $client->users()->whereKey($user->id)->exists()) {
            $client->users()->attach($user, ['is_owner' => true]);
        }

        return new ImportOutcome($user->id, $created ? ImportAction::Created : ImportAction::Updated);
    }

    public function finalize(ImportContext $context): array
    {
        return [];
    }

    public function cutover(ImportContext $context): int
    {
        return 0;
    }
}
