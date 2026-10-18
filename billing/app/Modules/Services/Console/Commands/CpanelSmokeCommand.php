<?php

namespace App\Modules\Services\Console\Commands;

use App\Data\Services\Provisioning\ClientSummary;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\PreviousPackage;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Data\Services\Provisioning\ServerConnection;
use App\Data\Services\Provisioning\ServiceSnapshot;
use App\Enums\DriverOutcome;
use App\Enums\ReconcileStatus;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CpanelDriver;
use App\Support\Integrations\Redactor;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the cPanel driver against a real WHM server (spec §12), never in CI. Read-only unless --lifecycle is given
 * with --confirm-lifecycle; then it creates one throwaway account (mhsmk + 3 characters) and removes only that
 * account. Builds the connection from the options alone: it reads and writes no database table.
 */
#[Signature('provisioning:cpanel-smoke
    {--host= : WHM hostname (required)}
    {--user=root : the WHM user the API token belongs to}
    {--port=2087 : WHM port}
    {--token-file= : a file holding the API token; without it the token is asked for, hidden}
    {--pin= : SPKI SHA-256 to trust instead of the certificate authority (self-signed servers)}
    {--lifecycle : also create, change and remove one throwaway account}
    {--confirm-lifecycle : required with --lifecycle: the run changes the server}
    {--domain= : the test domain for --lifecycle}
    {--package= : the package for --lifecycle}
    {--package2= : a second package, to test change package}
    {--record= : write redacted driver requests and responses to this directory}')]
#[Description('Check the cPanel driver against a real WHM server (read-only unless --lifecycle --confirm-lifecycle)')]
class CpanelSmokeCommand extends Command
{
    private bool $failed = false;

    /** @var array<string, mixed> */
    private array $recorded = [];

    public function handle(CpanelDriver $driver, Redactor $redactor): int
    {
        $host = strtolower(trim((string) $this->option('host')));

        if ($host === '') {
            $this->components->error('Give --host and an API token (--token-file, or type it when asked). Nothing was sent.');

            return self::FAILURE;
        }

        $token = $this->token();

        if ($token === '') {
            $this->components->error('Give --host and an API token (--token-file, or type it when asked). Nothing was sent.');

            return self::FAILURE;
        }

        if ($this->option('lifecycle') && ! $this->option('confirm-lifecycle')) {
            $this->components->error('--lifecycle creates and removes an account on the server; add --confirm-lifecycle to run it. Nothing was sent.');

            return self::FAILURE;
        }

        if ($this->option('lifecycle') && (trim((string) $this->option('domain')) === '' || trim((string) $this->option('package')) === '')) {
            $this->components->error('--lifecycle needs --domain and --package. Nothing was sent.');

            return self::FAILURE;
        }

        $server = new ServerConnection(
            id: 0,
            name: $host,
            hostname: $host,
            port: (int) $this->option('port'),
            username: (string) $this->option('user'),
            apiToken: $token,
            settings: ['login_method' => 'token', 'spki_pin' => (string) $this->option('pin'), 'sso_enabled' => true],
        );

        $this->readOnly($driver, $server);

        if ($this->option('lifecycle') && ! $this->failed) {
            $this->lifecycle($driver, $server);
        }

        $this->record($redactor, $server->secrets());

        if ($this->failed) {
            $this->components->error('At least one step failed.');

            return self::FAILURE;
        }

        $this->components->info('Every step passed.');

        return self::SUCCESS;
    }

    private function readOnly(CpanelDriver $driver, ServerConnection $server): void
    {
        $this->components->twoColumnDetail('<fg=gray>Read-only checks</>');

        $this->step('Test connection', function () use ($driver, $server): string {
            $check = $driver->testConnection($server);

            foreach ($check->steps as $step) {
                $this->line(sprintf('    %s  %s: %s', strtoupper($step->status->value), $step->label, $step->detail));
            }

            $this->recorded['test_connection'] = ['ok' => $check->ok, 'details' => $check->details];

            return $check->ok ? $check->message : throw new \RuntimeException($check->message);
        });

        if ($this->failed) {
            return;
        }

        $this->step('List accounts', fn (): string => count($driver->listAccounts($server)).' accounts visible.');
        $this->step('Usage', fn (): string => count($driver->usage($server)).' usage rows.');
    }

    private function lifecycle(CpanelDriver $driver, ServerConnection $server): void
    {
        $others = count($driver->listAccounts($server));

        if ($others > 0 && ! $this->confirm("The server holds {$others} accounts. The smoke test creates and removes only its own mhsmk account. Continue?")) {
            $this->components->warn('Lifecycle skipped.');

            return;
        }

        $this->components->twoColumnDetail('<fg=gray>Lifecycle</>');
        $username = 'mhsmk'.Str::lower(Str::random(3));
        $context = $this->smokeContext($server, $username, (string) $this->option('package'));
        $created = null;

        $this->step('Create', function () use ($driver, &$context, &$created): string {
            // A taken name comes back as a rename to try; follow it as the host would (at most 5 times).
            for ($try = 0; $try <= 5; $try++) {
                $result = $driver->create($context);
                $renamed = $result->outcome() === DriverOutcome::Retryable ? ($result->data['username'] ?? null) : null;

                if (! is_string($renamed)) {
                    break;
                }

                $context = $this->smokeContext($context->server ?? throw new \LogicException, $renamed, (string) $this->option('package'));
            }

            $result = $this->result('create', $result);

            // Only an account this run actually sent createacct for is ever changed or removed.
            if (! in_array('createacct', array_column((array) ($result->request['calls'] ?? []), 'function'), true)) {
                throw new \RuntimeException("{$context->service->username} was not created by this run; nothing else will be changed.");
            }

            $created = $result;

            return $result->message.' Read back: '.implode(', ', array_keys((array) ($result->data['driver_data'] ?? []))).'.';
        });

        if ($created === null) {
            $this->components->warn("Create did not complete. If WHM may have created {$context->service->username}, check it and remove it in WHM by hand.");

            return;
        }

        $this->step('Check created', fn (): string => $this->expect($driver->reconcile($context, 'create', CarbonImmutable::now()->subHour())->status, ReconcileStatus::Executed));
        $this->step('Suspend', fn (): string => (string) $this->result('suspend', $driver->suspend($context, 'Smoke test'))->message);
        $this->step('Check suspended', fn (): string => $this->expect($driver->reconcile($context, 'suspend', CarbonImmutable::now())->status, ReconcileStatus::Executed));
        $this->step('Unsuspend', fn (): string => (string) $this->result('unsuspend', $driver->unsuspend($context))->message);
        $this->step('Change password', fn (): string => (string) $this->result('change_password', $driver->changePassword($context, Str::password(20)))->message);

        if (trim((string) $this->option('package2')) !== '') {
            $changed = $this->smokeContext($context->server ?? throw new \LogicException, (string) $context->service->username, (string) $this->option('package2'));
            $this->step('Change package', fn (): string => (string) $this->result('change_package', $driver->changePackage($changed, new PreviousPackage))->message);
        }

        $this->step('Single sign-on', function () use ($driver, $context): string {
            $link = $driver->serviceSsoLink($context);

            return 'Session for '.parse_url($link->url, PHP_URL_HOST).' expires '.($link->expiresAt?->toDateTimeString() ?? 'unknown').'.';
        });

        // Only the account this run created is ever removed.
        $failedBefore = $this->failed;
        $this->failed = false;
        $this->step('Terminate', fn (): string => (string) $this->result('terminate', $driver->terminate($context))->message);
        $this->step('Check removed', fn (): string => $this->expect($driver->reconcile($context, 'terminate', CarbonImmutable::now())->status, ReconcileStatus::Executed));

        if ($this->failed) {
            $this->components->warn("The smoke account {$context->service->username} may still be on the server; remove it in WHM.");
        }

        $this->failed = $this->failed || $failedBefore;
    }

    /**
     * @param  Closure(): string  $run
     */
    private function step(string $label, Closure $run): void
    {
        $started = hrtime(true);

        try {
            $message = $run();
            $status = '<fg=green>PASS</>';
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            $status = '<fg=red>FAIL</>';
            $this->failed = true;
        }

        $this->components->twoColumnDetail(sprintf('%s <fg=gray>%d ms</>', $label, (hrtime(true) - $started) / 1_000_000), $status);
        $this->line('    '.$message);
    }

    private function result(string $command, DriverResult $result): DriverResult
    {
        $this->recorded[$command] = ['outcome' => $result->outcome()->value, 'message' => $result->message, 'request' => $result->request, 'response' => $result->response];

        return $result->success ? $result : throw new \RuntimeException((string) $result->message);
    }

    private function expect(ReconcileStatus $status, ReconcileStatus $expected): string
    {
        return $status === $expected ? "Server reports {$status->value}." : throw new \RuntimeException("Server reports {$status->value}, expected {$expected->value}.");
    }

    private function smokeContext(ServerConnection $server, string $username, string $package): ProvisioningContext
    {
        $domain = strtolower(trim((string) $this->option('domain')));

        return new ProvisioningContext(
            service: new ServiceSnapshot(id: 0, clientId: 0, productId: 0, status: 'pending', domain: $domain, username: $username, password: Str::password(20)),
            server: $server,
            client: new ClientSummary(0, 'Smoke test', "smoke@{$domain}"),
            productConfig: ['package' => $package, 'send_contact_email' => false],
        );
    }

    private function token(): string
    {
        $file = (string) $this->option('token-file');

        if ($file !== '') {
            return is_readable($file) ? trim((string) file_get_contents($file)) : '';
        }

        return $this->input->isInteractive() ? trim((string) $this->secret('WHM API token')) : '';
    }

    /**
     * @param  list<string>  $secrets
     */
    private function record(Redactor $redactor, array $secrets): void
    {
        $directory = (string) $this->option('record');

        if ($directory === '') {
            return;
        }

        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            $this->components->warn("Could not create {$directory}; nothing recorded.");

            return;
        }

        $path = rtrim($directory, '/').'/cpanel-smoke-'.CarbonImmutable::now()->format('Ymd-His').'.json';
        file_put_contents($path, json_encode($redactor->redact($this->recorded, $secrets), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->components->info("Redacted responses written to {$path}.");
    }
}
