<?php

namespace Tests\Fixtures\Extensions\AcmePanel;

use App\Contracts\Services\Provisioning\ChangesPassword;
use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Contracts\Services\Provisioning\TestsServerConnection;
use App\Data\Integrations\ConnectionCheck;
use App\Data\Integrations\SettingField;
use App\Data\Services\Provisioning\DriverMetadata;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Data\Services\Provisioning\ServerConnection;
use App\Enums\FieldType;
use App\Support\Services\RetryableProvisioningException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A made-up control panel driver shipped by an outside package. It imports only kernel types: if it ever needs a
 * module class, the kernel extension contracts are incomplete.
 *
 * The pretend API: POST /api/accounts, POST /api/accounts/{username}/{suspend|unsuspend|password}, DELETE
 * /api/accounts/{username}, GET /api/status. Bearer token auth.
 */
final class AcmePanelDriver implements ChangesPassword, ProvisioningDriver, TestsServerConnection
{
    public function key(): string
    {
        return 'acme_panel';
    }

    public function name(): string
    {
        return 'Acme Panel';
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            requiresServer: true,
            defaultPort: 8080,
            defaultSecurePort: 8443,
            productConfigFields: [
                new SettingField('plan', 'Acme plan', required: true, help: 'The plan name as it appears in Acme Panel.'),
                new SettingField('reseller_key', 'Reseller key', FieldType::Secret),
            ],
        );
    }

    public function create(ProvisioningContext $context): DriverResult
    {
        $request = ['username' => $context->service->username, 'domain' => $context->service->domain, 'plan' => $context->productConfig['plan'] ?? null, 'email' => $context->client->email, 'password' => $context->service->password, 'reseller' => $context->productConfig['reseller_key'] ?? null];

        return $this->send($context->server, 'post', '/api/accounts', $request, fn (Response $response): DriverResult => DriverResult::ok(
            'Acme account created.',
            ['driver_data' => ['acme_id' => (string) $response->json('id')]],
            $request,
            (array) $response->json(),
        ));
    }

    public function suspend(ProvisioningContext $context, string $reason): DriverResult
    {
        return $this->accountCall($context, 'suspend', ['reason' => $reason]);
    }

    public function unsuspend(ProvisioningContext $context): DriverResult
    {
        return $this->accountCall($context, 'unsuspend');
    }

    public function terminate(ProvisioningContext $context): DriverResult
    {
        return $this->send($context->server, 'delete', '/api/accounts/'.$context->service->username, [], fn (): DriverResult => DriverResult::ok('Acme account removed.'));
    }

    public function changePassword(ProvisioningContext $context, #[\SensitiveParameter] string $newPassword): DriverResult
    {
        return $this->accountCall($context, 'password', ['password' => $newPassword]);
    }

    public function testConnection(ServerConnection $server): ConnectionCheck
    {
        try {
            $response = $this->client($server)->get('/api/status');
        } catch (ConnectionException) {
            return new ConnectionCheck(false, "Couldn't reach {$server->hostname}.");
        }

        return $response->successful()
            ? new ConnectionCheck(true, "Acme Panel {$response->json('version')} on {$server->hostname} answered.")
            : new ConnectionCheck(false, "Acme Panel refused the API token (HTTP {$response->status()}).");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function accountCall(ProvisioningContext $context, string $action, array $payload = []): DriverResult
    {
        return $this->send($context->server, 'post', "/api/accounts/{$context->service->username}/{$action}", $payload, fn (): DriverResult => DriverResult::ok("Acme {$action} done.", request: $payload));
    }

    /**
     * Map every answer to one outcome: refused connection → retryable; timeout after sending → unknown; 4xx →
     * rejected; 5xx → retryable only when the panel says nothing ran.
     *
     * @param  'post'|'delete'  $method
     * @param  array<string, mixed>  $payload
     * @param  \Closure(Response): DriverResult  $onSuccess
     */
    private function send(?ServerConnection $server, string $method, string $path, array $payload, \Closure $onSuccess): DriverResult
    {
        if ($server === null) {
            return DriverResult::fail('Acme Panel needs a server.');
        }

        try {
            $response = $this->client($server)->{$method}($path, $payload);
        } catch (ConnectionException $exception) {
            if (str_contains($exception->getMessage(), 'timed out')) {
                return DriverResult::unknown('Acme Panel did not answer in time; check the account before retrying.', $payload);
            }

            throw new RetryableProvisioningException("Couldn't connect to {$server->hostname}.", previous: $exception);
        }

        return match (true) {
            $response->successful() => $onSuccess($response),
            $response->status() === 503 => DriverResult::fail('Acme Panel is busy.', retryable: true, request: $payload),
            default => DriverResult::fail('Acme Panel refused the request: '.($response->json('error') ?? "HTTP {$response->status()}"), request: $payload, response: (array) $response->json()),
        };
    }

    private function client(ServerConnection $server): PendingRequest
    {
        $scheme = $server->secure ? 'https' : 'http';
        $port = $server->port ?? ($server->secure ? 8443 : 8080);

        return Http::baseUrl("{$scheme}://{$server->hostname}:{$port}")->withToken((string) $server->apiToken)->acceptJson()->timeout(20);
    }
}
