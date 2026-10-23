<?php

namespace App\Modules\Core\Console\Commands;

use App\Support\Integrations\StoredSecrets;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Read-only: lists the stored secrets shorter than their field's minimum, by name, never by value, and the records
 * that could not be decrypted. Exits 0 when there is neither, 1 when only short secrets are found, and 2 when a record
 * could not be decrypted (the list may then be incomplete).
 */
#[Signature('integrations:short-secrets')]
#[Description('List stored integration secrets shorter than their minimum length (names only)')]
class ListShortSecretsCommand extends Command
{
    public const UNREADABLE = 2;

    public function handle(StoredSecrets $secrets): int
    {
        ['short' => $short, 'unreadable' => $unreadable] = $secrets->check();

        if ($short === [] && $unreadable === []) {
            $this->components->info('No stored secret is shorter than its minimum length.');

            return self::SUCCESS;
        }

        $this->table(
            ['Area', 'Integration', 'Setting', 'Minimum'],
            [
                ...array_map(fn (array $row): array => [$row['source'], $row['owner'], "{$row['label']} ({$row['key']})", $row['minimum']], $short),
                ...array_map(fn (array $row): array => [$row['source'], $row['owner'], 'Cannot be decrypted: not checked', '-'], $unreadable),
            ],
        );

        if ($short !== []) {
            $this->components->warn('Replace each short secret on its settings form: the Redactor does not match a very short value, so logs mask it only where its setting name appears.');
        }

        if ($unreadable !== []) {
            $this->components->error('Some records cannot be decrypted with the configured keys. Check APP_KEY and APP_PREVIOUS_KEYS, or enter those credentials again.');

            return self::UNREADABLE;
        }

        return self::FAILURE;
    }
}
