<?php

namespace App\Modules\Core\Install;

use App\Modules\Core\Support\AutomationStatus;
use App\Modules\Core\Support\Settings;
use App\Support\Integrations\Endpoints;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The install pre-flight. APP_KEY, the database and migrations block the install; the queue, scheduler, object
 * storage, debug mode and mail are reported as warnings, because the install itself does not need them.
 */
final class EnvironmentChecks
{
    /** A scheduler heartbeat older than this is reported as stale. */
    private const HEARTBEAT_STALE_MINUTES = 5;

    public function __construct(private Migrator $migrator, private AutomationStatus $automation, private Settings $settings) {}

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
            $this->appKey(),
            $database,
            $migrations,
            $this->queue(),
            $this->queueTimeouts(),
            $migrations->failed() || $migrations->status === CheckStatus::Skipped
                ? new EnvironmentCheck('Scheduler', CheckStatus::Skipped, 'Needs a migrated database.')
                : $this->scheduler(),
            $this->objectStorage(),
            ...$this->integrationOverrides(),
            ...$this->production(databaseReady: $migrations->status === CheckStatus::Ok),
        ];
    }

    private function appKey(): EnvironmentCheck
    {
        $key = (string) config('app.key');

        if ($key === '') {
            return new EnvironmentCheck('APP_KEY', CheckStatus::Failed, 'APP_KEY is not set. Run `php artisan key:generate` once and keep the key: it decrypts stored secrets.');
        }

        $raw = str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;

        if (! Encrypter::supported($raw, (string) config('app.cipher'))) {
            return new EnvironmentCheck('APP_KEY', CheckStatus::Failed, 'APP_KEY does not fit the configured cipher. Generate one with `php artisan key:generate --show`.');
        }

        return new EnvironmentCheck('APP_KEY', CheckStatus::Ok, 'Set.');
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

    private function queueTimeouts(): EnvironmentCheck
    {
        $problems = (new QueueTimeouts)->problems();

        if ($problems !== []) {
            return new EnvironmentCheck('Queue timeouts', CheckStatus::Failed, implode(' ', $problems));
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
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! str_starts_with($url, 'https://') || in_array($host, ['', 'localhost', '127.0.0.1', '::1'], true)) {
            $checks[] = new EnvironmentCheck('APP_URL', CheckStatus::Warning, "APP_URL is \"{$url}\". Every link (password resets, verification, signed downloads, mail) is built from it and other hosts are rejected"
                .(str_starts_with($url, 'https://') ? '' : '; with http:// in production, password-reset, verification and invitation emails are held')
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
}
