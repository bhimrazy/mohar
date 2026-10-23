<?php

namespace App\Modules\Core\Console\Commands;

use App\Support\Integrations\StoredSecrets;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Read-only: lists the stored secret settings shorter than their field's minimum, by name, never by value. Exits 1
 * when any is found, so a deploy or monitoring script can check it.
 */
#[Signature('integrations:short-secrets')]
#[Description('List stored integration secrets shorter than their minimum length (names only)')]
class ListShortSecretsCommand extends Command
{
    public function handle(StoredSecrets $secrets): int
    {
        $found = $secrets->findTooShort();

        if ($found === []) {
            $this->components->info('No stored secret is shorter than its minimum length.');

            return self::SUCCESS;
        }

        $this->table(
            ['Area', 'Integration', 'Setting', 'Minimum'],
            array_map(fn (array $row): array => [$row['source'], $row['owner'], "{$row['label']} ({$row['key']})", $row['minimum']], $found),
        );
        $this->components->warn('Replace each one on its settings form. Logs mask a value under 4 characters only where its setting name appears.');

        return self::FAILURE;
    }
}
