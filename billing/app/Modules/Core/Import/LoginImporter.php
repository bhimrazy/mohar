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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The owner login of an imported client (`user`, client-import spec §6.11). Attributes: `email`, `name`,
 * `verified`, `link_existing`; link `client_id`. A new login gets the hash of a random secret nobody keeps (one per
 * importer instance, so a chunk hashes once), so no password signs in until the invitation sets one. An existing login
 * is never linked on its own: only when staff chose to link it and it is still linkable(); a staff login never is.
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

    /**
     * Whether staff may link an existing login to a new client: a client login whose email was verified at least 30
     * days ago (changing the email removes the verification, so the address has been the login's for that long).
     */
    public static function linkable(?User $login): bool
    {
        return $login !== null && ! $login->is_staff && $login->email_verified_at !== null
            && CarbonImmutable::parse($login->email_verified_at)->lte(CarbonImmutable::now()->subDays(30));
    }

    public function upsert(ImportRecord $record, ImportContext $context, ?int $existingTargetId): ImportOutcome
    {
        $email = mb_strtolower((string) $record->attributes['email']);
        $user = User::query()->where('email', $email)->first();

        if ($user?->is_staff) {
            return new ImportOutcome(null, ImportAction::Skipped, [new ImportIssue(ImportSeverity::Warning, 'staff_login', __('This email belongs to a staff login, so no login was created for the client.'))]);
        }

        if ($user !== null && ! (($record->attributes['link_existing'] ?? false) === true && self::linkable($user))) {
            return new ImportOutcome(null, ImportAction::Skipped, [new ImportIssue(ImportSeverity::Info, 'login_not_linked', __('A login with this email exists and was not linked; the client was created without a login.'))]);
        }

        // A dry run may be asked about a client it did not create: it resolves the link only when writing.
        if ($context->dryRun) {
            return new ImportOutcome($user?->id, $user === null ? ImportAction::Created : ImportAction::Updated);
        }

        $client = Client::query()->findOrFail($record->links['client_id'] ?? 0);
        $created = $user === null;

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
