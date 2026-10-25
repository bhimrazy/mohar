<?php

namespace App\Modules\Core\Install;

use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\AutomationStatus;
use App\Modules\Core\Support\Settings;
use App\Support\Integrations\Endpoints;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The install pre-flight. APP_KEY, the database and migrations block the install; the queue, scheduler, object
 * storage, debug mode and mail are reported as warnings, because the install itself does not need them. A vulnerable
 * PHP build blocks only a first production install.
 */
final class EnvironmentChecks
{
    /** A scheduler heartbeat older than this is reported as stale. */
    private const HEARTBEAT_STALE_MINUTES = 5;

    public function __construct(private Migrator $migrator, private AutomationStatus $automation, private Settings $settings, private PhpSecurityRelease $php) {}

    /**
     * @return list<EnvironmentCheck>
     */
    public function run(): array
    {
        $database = $this->database();
        $migrations = $database->failed()
            ? new EnvironmentCheck('Migrations', CheckStatus::Skipped, 'Needs a reachable database.')
            : $this->migrations();

        return [
            $this->phpRuntime(),
            $this->appKey(),
            $database,
            $migrations,
            $this->queue(),
            $this->lockStore(),
            $this->queueTimeouts(),
            $migrations->failed() || $migrations->status === CheckStatus::Skipped
                ? new EnvironmentCheck('Scheduler', CheckStatus::Skipped, 'Needs a migrated database.')
                : $this->scheduler(),
            $this->objectStorage(),
            ...$this->integrationOverrides(),
            ...$this->production(databaseReady: $migrations->status === CheckStatus::Ok),
        ];
    }

    /**
     * A PHP build with a known critical vulnerability (PhpSecurityRelease). A first production install stops, so a
     * vulnerable runtime never starts serving; everywhere else it warns: the re-run after an upgrade must not block a
     * deploy while the host's PHP package catches up, and development machines and CI do not serve public requests.
     */
    private function phpRuntime(): EnvironmentCheck
    {
        $problem = $this->php->problem();

        if ($problem === null) {
            return new EnvironmentCheck('PHP', CheckStatus::Ok, 'PHP '.$this->php->version().'.');
        }

        return new EnvironmentCheck('PHP', app()->isProduction() && $this->firstInstall() ? CheckStatus::Failed : CheckStatus::Warning, $problem);
    }

    /** No currencies yet: `app:install` has never completed here. */
    private function firstInstall(): bool
    {
        return ! rescue(fn (): bool => Currency::query()->exists(), false, report: false);
    }

    private function appKey(): EnvironmentCheck
    {
        return (new AppKeyCheck)->run();
    }

    private function database(): EnvironmentCheck
    {
        try {
            $connection = DB::connection();
            $connection->getPdo();

            return new EnvironmentCheck('Database', CheckStatus::Ok, "Connected ({$connection->getDriverName()}).");
        } catch (Throwable $exception) {
            return new EnvironmentCheck('Database', CheckStatus::Failed, 'Cannot connect: '.self::firstLine($exception).' Check the DB_* settings in .env.');
        }
    }

    private function migrations(): EnvironmentCheck
    {
        try {
            return $this->migrator->usingConnection(null, function (): EnvironmentCheck {
                if (! $this->migrator->repositoryExists()) {
                    return new EnvironmentCheck('Migrations', CheckStatus::Failed, 'The database has no tables yet. Run `php artisan migrate --force`.');
                }

                $files = $this->migrator->getMigrationFiles([...$this->migrator->paths(), database_path('migrations')]);
                $pending = array_values(array_diff(array_keys($files), $this->migrator->getRepository()->getRan()));

                if ($pending !== []) {
                    sort($pending);

                    return new EnvironmentCheck('Migrations', CheckStatus::Failed, count($pending).' pending (first: '.$pending[0].'). Run `php artisan migrate --force`.');
                }

                return new EnvironmentCheck('Migrations', CheckStatus::Ok, count($files).' applied.');
            });
        } catch (Throwable $exception) {
            return new EnvironmentCheck('Migrations', CheckStatus::Failed, 'Cannot read the migration status: '.self::firstLine($exception));
        }
    }

    private function queue(): EnvironmentCheck
    {
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);

        if (in_array($driver, ['sync', 'null'], true)) {
            return new EnvironmentCheck('Queue', CheckStatus::Warning, "QUEUE_CONNECTION uses the {$driver} driver: provisioning, mail and webhooks would run inside web requests or not at all. Use database or redis and run workers.");
        }

        return new EnvironmentCheck('Queue', CheckStatus::Ok, "{$connection} ({$driver}). Workers must be running: see the next steps.");
    }

    /**
     * The cache lock store must be shared (CacheLockStore). A store that cannot exclude other processes stops a first
     * production install; on a re-run after an upgrade, in development and in tests it warns, so a deploy is not blocked.
     */
    private function lockStore(): EnvironmentCheck
    {
        $result = (new CacheLockStore)->assess();

        if ($result['status'] === CheckStatus::Failed && ! (app()->isProduction() && $this->firstInstall())) {
            return new EnvironmentCheck('Cache lock store', CheckStatus::Warning, $result['detail']);
        }

        return new EnvironmentCheck('Cache lock store', $result['status'], $result['detail']);
    }

    private function queueTimeouts(): EnvironmentCheck
    {
        $problems = (new QueueTimeouts)->problems();

        if ($problems !== []) {
            // A first install stops, so the value is fixed before any job runs. `app:install` is also the re-run after
            // an upgrade, where a stop would block a deploy over a value the operator already lives with: warn there.
            return new EnvironmentCheck('Queue timeouts', $this->firstInstall() ? CheckStatus::Failed : CheckStatus::Warning, implode(' ', $problems));
        }

        $connection = (string) config('queue.default');

        if ((string) config("queue.connections.{$connection}.driver") === 'sqs') {
            return new EnvironmentCheck('Queue timeouts', CheckStatus::Warning, 'SQS has no retry_after: set the visibility timeout of each queue above the longest job timeout (see docs/devops/install.md §6).');
        }

        return new EnvironmentCheck('Queue timeouts', CheckStatus::Ok, 'retry_after is above the longest job timeout.');
    }

    private function scheduler(): EnvironmentCheck
    {
        try {
            $heartbeat = $this->automation->heartbeatAt();
        } catch (Throwable $exception) {
            return new EnvironmentCheck('Scheduler', CheckStatus::Warning, 'Cannot read the scheduler heartbeat: '.self::firstLine($exception));
        }

        if ($heartbeat === null) {
            return new EnvironmentCheck('Scheduler', CheckStatus::Warning, 'No heartbeat yet. Add the cron entry from the next steps; it beats every minute.');
        }

        if ($heartbeat->lt(now()->subMinutes(self::HEARTBEAT_STALE_MINUTES))) {
            return new EnvironmentCheck('Scheduler', CheckStatus::Warning, 'Last heartbeat '.$heartbeat->diffForHumans().'. Check the cron entry.');
        }

        return new EnvironmentCheck('Scheduler', CheckStatus::Ok, 'Heartbeat '.$heartbeat->diffForHumans().'.');
    }

    private function objectStorage(): EnvironmentCheck
    {
        $probe = '.install-check/'.Str::random(16);

        try {
            $disk = Storage::disk('objects');

            if (! $disk->put($probe, 'ok') || $disk->get($probe) !== 'ok') {
                return new EnvironmentCheck('Object storage', CheckStatus::Warning, 'The objects disk did not accept a test file. Run `php artisan objects:ensure-bucket` and check the OBJECTS_* settings.');
            }

            $disk->delete($probe);

            return new EnvironmentCheck('Object storage', CheckStatus::Ok, 'The objects disk is writable ('.config('filesystems.disks.objects.driver').').');
        } catch (Throwable $exception) {
            return new EnvironmentCheck('Object storage', CheckStatus::Warning, 'The objects disk is not writable: '.self::firstLine($exception).' Run `php artisan objects:ensure-bucket` and check the OBJECTS_* settings.');
        }
    }

    /**
     * Simulator endpoint overrides (ADR 0016) belong to development and tests only; anywhere else they are ignored, and
     * their presence means the environment was copied from a development machine.
     *
     * @return list<EnvironmentCheck>
     */
    private function integrationOverrides(): array
    {
        $keys = app(Endpoints::class)->configuredKeys();

        if ($keys === [] || Endpoints::developmentEnvironment(app())) {
            return [];
        }

        return [new EnvironmentCheck('Integration endpoints', CheckStatus::Failed, 'INTEGRATION_ENDPOINTS is set ('.implode(', ', $keys).') outside a development environment. It is ignored here; remove it and INTEGRATION_CA_BUNDLE from the environment.')];
    }

    /**
     * @return list<EnvironmentCheck>
     */
    private function production(bool $databaseReady): array
    {
        if (! app()->isProduction()) {
            return [];
        }

        $checks = [];

        if ((bool) config('app.debug')) {
            $checks[] = new EnvironmentCheck('Debug mode', CheckStatus::Warning, 'APP_DEBUG is true: error pages would show code and configuration. Set APP_DEBUG=false.');
        }

        $url = (string) config('app.url');
        $https = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';

        if (! $https || self::localHost((string) parse_url($url, PHP_URL_HOST))) {
            $checks[] = new EnvironmentCheck('APP_URL', CheckStatus::Warning, "APP_URL is \"{$url}\". Every link (password resets, verification, signed downloads, mail) is built from it and other hosts are rejected"
                .($https ? '' : '; with http:// in production, password-reset, verification and invitation emails are held')
                .': set the public https:// address.');
        }

        $mailer = (string) config('mail.default');

        if (in_array((string) config("mail.mailers.{$mailer}.transport", $mailer), ['log', 'array'], true)) {
            $checks[] = new EnvironmentCheck('Mail', CheckStatus::Warning, "MAIL_MAILER={$mailer} sends nothing. Security notices and invoices need real mail: set the MAIL_* settings.");
        }

        // Staff 2FA and the forced change of a one-time password are enforced by the Platform module's middleware.
        if (! app()->providerIsLoaded('App\\Modules\\Platform\\PlatformServiceProvider')) {
            $checks[] = new EnvironmentCheck('Staff security', CheckStatus::Warning, 'The Platform module is not loaded: staff two-factor authentication and the forced change of a one-time password are not enforced.');
        } elseif ($databaseReady && ! (bool) $this->settings->get('security.staff_2fa_required', true)) {
            $checks[] = new EnvironmentCheck('Staff security', CheckStatus::Warning, 'Two-factor authentication is not required for staff. Turn on "Require two-factor authentication for staff" in Admin → Settings → Security.');
        }

        return $checks;
    }

    private static function firstLine(Throwable $exception): string
    {
        return Str::limit(trim(strtok($exception->getMessage(), "\n") ?: $exception::class), 200);
    }

    /**
     * A host no visitor can reach: empty, localhost and its subdomains, mDNS `.local`, loopback or unspecified IPs.
     */
    private static function localHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return $host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
            || in_array($host, ['0.0.0.0', '::', '::1'], true) || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.'));
    }
}
