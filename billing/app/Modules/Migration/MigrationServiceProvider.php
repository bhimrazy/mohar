<?php

namespace App\Modules\Migration;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\InstallSecrets;
use App\Modules\Migration\Models\MigrationRecord;
use App\Modules\Migration\Models\MigrationRun;
use App\Support\Automation\Automation;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Security\EncryptedModel;
use App\Support\Security\EncryptedStorage;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Storage;

/**
 * Client import (client-import spec): CSV files today, WHMCS through its API later, one pipeline for both.
 */
class MigrationServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'migration';
    }

    protected function bootModule(): void
    {
        // Encrypted casts, for `security:reencrypt` (ADR 0022).
        $encrypted = $this->app->make(EncryptedStorage::class);
        $encrypted->register('migration_runs', new EncryptedModel(MigrationRun::class));
        $encrypted->register('migration_records', new EncryptedModel(MigrationRecord::class));
        $this->app->make(InstallSecrets::class)->define(MigrationRecord::EMAIL_HASH_SECRET, MigrationRecord::emailHashKey(...));

        // Step-up is Platform's middleware; without Platform, fall back to the framework's password confirmation.
        $router = $this->app->make(Router::class);

        if (! array_key_exists('password.recent', $router->getMiddleware())) {
            $router->aliasMiddleware('password.recent', RequirePassword::class);
        }

        $this->app->make(PermissionRegistry::class)->register('Migration', [
            'migration.runs.view' => 'See client imports and their checks',
            'migration.runs.execute' => 'Upload client files, map and check them (reads client data)',
        ], sensitive: ['migration.runs.execute']);

        $this->app->make(Navigation::class)->add('admin', new NavItem('Import clients', 'admin.migration.imports.index', 'FileUp', 'Clients', 50, 'migration.runs.view', 'admin.migration.imports.*'));

        $this->app->make(SettingsRegistry::class)->section('migration', 'Client import', [
            new SettingDefinition('migration.csv.max_bytes', 'Largest file (bytes)', 'integer', 'PHP\'s upload_max_filesize also applies.', rules: ['integer', 'min:1048576', 'max:104857600']),
            new SettingDefinition('migration.csv.max_rows', 'Most rows per file', 'integer', rules: ['integer', 'min:100', 'max:500000']),
            new SettingDefinition('migration.csv.max_columns', 'Most columns per file', 'integer', rules: ['integer', 'min:10', 'max:500']),
            new SettingDefinition('migration.staging_days', 'Days an unapplied upload is kept', 'integer', 'Its rows are then purged; counts stay.', rules: ['integer', 'min:1', 'max:30']),
            new SettingDefinition('migration.undo_days', 'Days an applied import keeps its rows', 'integer', 'After that its row details are purged; counts and outcomes stay.', rules: ['integer', 'min:1', 'max:30']),
        ], 'migration.runs.execute');

        $this->app->make(Automation::class)->task('migration.purge', Automation::HOUSEKEEPING, fn (CarbonImmutable $today): int => $this->purgeExpired(),
            label: 'Purge expired client imports', description: 'Removes the rows of client imports that were never applied once they expire; counts stay.');
    }

    /**
     * Client-import spec §5.8: a run's file and row payloads are removed when it expires: 7 days after upload when it was
     * never applied, at the end of the undo window when it was, and STOPPED_PURGE_DAYS after the last activity of an
     * import that stopped partway and was never continued (it keeps its status; it can no longer be continued).
     * Idempotent.
     */
    private function purgeExpired(): int
    {
        $purged = 0;

        MigrationRun::query()->whereNull('purged_at')->where(fn ($query) => $query
            ->where(fn ($query) => $query->where('expires_at', '<=', now())->where(fn ($query) => $query->whereNull('applied_at')->orWhere('status', MigrationRun::COMPLETED)))
            ->orWhere(fn ($query) => $query->whereNotNull('applied_at')->whereIn('status', [MigrationRun::FAILED, MigrationRun::APPLYING])
                ->where('heartbeat_at', '<=', now()->subDays(MigrationRun::STOPPED_PURGE_DAYS))))
            ->lazyById(100)->each(function (MigrationRun $run) use (&$purged): void {
                if ($run->file_key !== null) {
                    Storage::disk('objects')->delete($run->file_key);
                }

                MigrationRecord::query()->where('migration_run_id', $run->id)->update(['raw' => null, 'normalized' => null, 'before' => null]);
                $run->forceFill(['status' => $run->applied_at !== null ? ($run->status === MigrationRun::APPLYING ? MigrationRun::FAILED : $run->status) : MigrationRun::EXPIRED, 'file_key' => null, 'header' => null, 'found_values' => null, 'purged_at' => now()])->save();
                app(ActivityLogger::class)->log('Client import rows purged', $run, properties: ['file' => $run->file_name, 'rows' => $run->row_count], event: 'migration.purged');
                $purged++;
            });

        return $purged;
    }
}
