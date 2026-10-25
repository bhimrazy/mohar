<?php

namespace App\Modules\Services\Actions;

use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Exceptions\ServiceActionException;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\AccountImportMatcher;
use App\Modules\Services\Support\AccountList;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Import accounts, linked rows (cPanel spec §10.2, #139): staff accept what the server says about an account into the
 * service that already holds it: its domain, dedicated IP or suspension. Nothing is sent to the server, and the
 * product, client and price are never touched.
 *
 * Confirm-then-apply: the page shows each change (changes()) with a digest of what it showed; apply() re-reads the
 * account and the service under the service's row lock and applies only when the changes it finds are the ones
 * digested. A status change goes through SetServiceStatus, so the state machine and the module lock still apply.
 */
class AcceptServerValues
{
    public const FIELDS = ['domain', 'ip', 'status'];

    public function __construct(private AccountList $list, private AccountImportMatcher $matcher, private SetServiceStatus $status, private ActivityLogger $activity) {}

    /**
     * What the server says differently from the service, for the fields staff may accept.
     *
     * @param  array<string, mixed>  $account  the stored account
     * @return list<array{field: string, label: string, server: string, local: string, warning?: string|null}>
     */
    public static function changes(array $account, Service $service): array
    {
        $changes = [];
        $domain = isset($account['domain']) ? mb_strtolower((string) $account['domain']) : null;

        if ($domain !== null && $domain !== '' && mb_strtolower((string) $service->domain) !== $domain) {
            $changes[] = ['field' => 'domain', 'label' => AccountImportMatcher::text('Domain'), 'server' => $domain, 'local' => $service->domain ?? ''];
        }

        $ip = $service->driver_data['ip'] ?? null;

        if (($account['ip'] ?? null) !== null && $account['ip'] !== '' && $account['ip'] !== $ip) {
            $changes[] = ['field' => 'ip', 'label' => AccountImportMatcher::text('IP address'), 'server' => (string) $account['ip'], 'local' => is_string($ip) ? $ip : ''];
        }

        $suspended = (bool) ($account['suspended'] ?? false);

        if ($suspended && $service->status === ServiceStatus::Active) {
            $changes[] = ['field' => 'status', 'label' => AccountImportMatcher::text('Status'), 'server' => ServiceStatus::Suspended->value, 'local' => ServiceStatus::Active->value, 'warning' => null];
        } elseif (! $suspended && $service->status === ServiceStatus::Suspended) {
            // Mohar suspended it for an unpaid invoice: reactivating it only lasts until the next automation run.
            $changes[] = ['field' => 'status', 'label' => AccountImportMatcher::text('Status'), 'server' => ServiceStatus::Active->value, 'local' => ServiceStatus::Suspended->value,
                'warning' => $service->suspensionKind() === 'overdue' ? AccountImportMatcher::text('This service is suspended for an overdue invoice. It is suspended again, and the client emailed, by the next automation run until the invoice is paid.') : null];
        }

        return $changes;
    }

    /**
     * Binds a request to the changes the page showed: the service, and each field with both values.
     *
     * @param  list<array{field: string, label: string, server: string, local: string}>  $changes
     */
    public static function digest(int $serviceId, array $changes): string
    {
        return hash('sha256', json_encode([$serviceId, array_map(fn (array $change): array => [$change['field'], $change['server'], $change['local']], $changes)], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<string>  $fields  the changes to accept, a subset of FIELDS
     * @param  string  $digest  the digest the page showed for exactly those changes
     *
     * @throws ValidationException when the list, account or service is gone, or the changes are not the ones shown
     */
    public function handle(Server $server, string $username, array $fields, string $digest, ?User $staff): void
    {
        $service = DB::transaction(function () use ($server, $username, $fields, $digest, $staff): Service {
            Server::query()->whereKey($server->id)->lockForUpdate()->first();
            $import = FetchAccountsForImport::latest($server);

            if ($import?->status !== ServerAccountImport::READY) {
                throw ValidationException::withMessages(['import' => __('Fetch the accounts on :server again: the list is missing or expired.', ['server' => $server->name])]);
            }

            $account = $this->list->rows($import, [$username])[$username] ?? null;
            $service = Service::query()->where('server_id', $server->id)->whereIn('status', ServiceStatus::occupyingValues())
                ->whereRaw('lower(username) = ?', [mb_strtolower($username)])->lockForUpdate()->first();

            if ($account === null || $service === null) {
                throw ValidationException::withMessages(['import' => __('This account or its service is no longer there. Match again.')]);
            }

            $changes = array_values(array_filter(self::changes($account, $service), fn (array $change): bool => in_array($change['field'], $fields, true)));

            if ($changes === [] || count($changes) !== count(array_unique($fields)) || ! hash_equals(self::digest($service->id, $changes), $digest)) {
                throw ValidationException::withMessages(['import' => __('The account or its service changed since the page showed it. Check the differences and accept again.')]);
            }

            foreach ($changes as $change) {
                match ($change['field']) {
                    'domain' => $service->update(['domain' => $change['server']]),
                    'ip' => $service->update(['driver_data' => [...($service->driver_data ?? []), 'ip' => $change['server']]]),
                    default => $this->setStatus($service, $account),
                };
            }

            $this->activity->log("Accepted the server's values for {$service->label()}", $service, $service->client_id, [
                'server' => $server->name, 'username' => $username, 'fields' => array_column($changes, 'field'),
                'before' => array_column($changes, 'local', 'field'), 'after' => array_column($changes, 'server', 'field'),
            ], actor: $staff);

            return $service;
        });

        // Show the next view of the row from the service as it is now.
        $this->refreshRow($server, $service, $username);
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function setStatus(Service $service, array $account): void
    {
        $suspended = (bool) ($account['suspended'] ?? false);
        $note = $suspended ? 'imported (accepted from the server: suspended there'.(($account['suspend_reason'] ?? null) ? ': '.$account['suspend_reason'] : '').')' : 'imported (accepted from the server: active there)';

        try {
            $this->status->handle($service, $suspended ? ServiceStatus::Suspended : ServiceStatus::Active, mb_substr($note, 0, 180));
        } catch (ServiceActionException $exception) {
            throw ValidationException::withMessages(['import' => $exception->getMessage()]);
        }
    }

    private function refreshRow(Server $server, Service $service, string $username): void
    {
        $import = FetchAccountsForImport::latest($server);

        if ($import?->status !== ServerAccountImport::READY) {
            return;
        }

        $account = $this->list->rows($import, [$username])[$username] ?? null;

        if ($account === null) {
            return;
        }

        $keys = array_flip(['category', 'client_id', 'product_id', 'service_id', 'note', 'differences']);
        $fresh = $this->matcher->classifyRows($this->matcher->context($server, [mb_strtolower($username)]), [array_diff_key($account, $keys)], $import->connection_user)[0];
        $this->list->rewrite($import, [$username => $fresh], [$username => $fresh['category']]);
    }
}
