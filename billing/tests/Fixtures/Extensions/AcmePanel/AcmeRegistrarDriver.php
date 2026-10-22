<?php

namespace Tests\Fixtures\Extensions\AcmePanel;

use App\Contracts\Domains\Registrar\ChecksAvailability;
use App\Contracts\Domains\Registrar\ManagesNameservers;
use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Contracts\Domains\Registrar\TestsRegistrarConnection;
use App\Data\Domains\Registrar\AvailabilityResult;
use App\Data\Domains\Registrar\DomainInfo;
use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Domains\Registrar\RegistrationRequest;
use App\Data\Integrations\ConnectionCheck;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Support\Domains\RegistrarException;
use App\Support\Domains\RegistrarTimeout;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A made-up registrar driver shipped by an outside package. It imports only kernel types: if it ever needs a module
 * class, the kernel registrar contracts are incomplete.
 *
 * The pretend API (JSON, key in a header): GET /check?sld=&tlds=, POST /domains, POST /domains/{name}/renew,
 * GET /domains/{name}, PUT /domains/{name}/nameservers, GET /account.
 */
final class AcmeRegistrarDriver implements ChecksAvailability, ManagesNameservers, RegistrarDriver, TestsRegistrarConnection
{
    public function key(): string
    {
        return 'acme_registrar';
    }

    public function name(): string
    {
        return 'Acme Registrar';
    }

    public function settingsFields(): array
    {
        return [
            new SettingField('reseller_id', 'Reseller ID', required: true),
            new SettingField('api_key', 'API key', FieldType::Secret, required: true),
            new SettingField('sandbox_api_key', 'Sandbox API key', FieldType::Secret),
        ];
    }

    public function checkAvailability(RegistrarConfig $config, string $sld, array $tlds): array
    {
        $answer = $this->send($config, 'get', '/check', ['sld' => $sld, 'tlds' => implode(',', $tlds)], mutating: false);

        return array_map(fn (string $tld): AvailabilityResult => new AvailabilityResult($sld.$tld, match ($answer->json("results.{$tld}")) {
            'free' => 'available',
            'taken' => 'registered',
            default => 'unknown',
        }), $tlds);
    }

    public function register(RegistrarConfig $config, RegistrationRequest $request): RegistrarResult
    {
        $answer = $this->send($config, 'post', '/domains', ['name' => $request->domain->name, 'years' => $request->years, 'nameservers' => $request->nameservers]);

        return $this->result($answer, 'Registered at Acme.');
    }

    public function renew(RegistrarConfig $config, DomainRef $domain, int $years): RegistrarResult
    {
        return $this->result($this->send($config, 'post', "/domains/{$domain->name}/renew", ['years' => $years, 'current_expiry' => $domain->expiryDate?->toDateString()]), 'Renewed at Acme.');
    }

    public function info(RegistrarConfig $config, DomainRef $domain): DomainInfo
    {
        $answer = $this->send($config, 'get', "/domains/{$domain->name}", mutating: false);

        return new DomainInfo(
            status: $answer->status() === 404 ? 'not_found' : (string) $answer->json('status', 'active'),
            nameservers: array_values(array_map('strval', (array) $answer->json('nameservers', []))),
            expiryDate: is_string($answer->json('expires')) ? CarbonImmutable::parse($answer->json('expires')) : null,
            remote: is_int($answer->json('id')) ? ['acme_id' => $answer->json('id')] : [],
        );
    }

    public function setNameservers(RegistrarConfig $config, DomainRef $domain, array $nameservers): RegistrarResult
    {
        return $this->result($this->send($config, 'put', "/domains/{$domain->name}/nameservers", ['nameservers' => $nameservers]), 'Nameservers updated at Acme.');
    }

    public function testConnection(RegistrarConfig $config): ConnectionCheck
    {
        try {
            $answer = $this->send($config, 'get', '/account', mutating: false);
        } catch (RegistrarException $error) {
            return new ConnectionCheck(false, $error->getMessage());
        }

        return new ConnectionCheck(true, 'Acme answered for reseller '.$config->setting('reseller_id').'; balance '.$answer->json('balance').'.');
    }

    private function result(Response $answer, string $message): RegistrarResult
    {
        return new RegistrarResult(
            $answer->json('state') === 'queued' ? 'pending' : 'completed',
            is_string($answer->json('expires')) ? CarbonImmutable::parse($answer->json('expires')) : null,
            $message,
            remote: is_int($answer->json('id')) ? ['acme_id' => $answer->json('id')] : [],
        );
    }

    /**
     * Map every answer to one outcome: no connection → retryable; no answer after sending a write → unknown
     * (RegistrarTimeout); 4xx → rejected; 5xx on a write → unknown, on a read → retryable. A 404 from a read is an
     * answer (not found), not an error.
     *
     * @param  'get'|'post'|'put'  $method
     * @param  array<string, mixed>  $payload
     */
    private function send(RegistrarConfig $config, string $method, string $path, array $payload = [], bool $mutating = true): Response
    {
        try {
            $answer = $this->client($config)->{$method}($path, $payload);
        } catch (ConnectionException $error) {
            if ($mutating && ! preg_match('/cURL error (6|7)\b/', $error->getMessage())) {
                throw new RegistrarTimeout('Acme did not answer; the outcome is unknown.');
            }

            throw new RegistrarException("Couldn't reach Acme.", retryable: true, previous: $error);
        }

        return match (true) {
            $answer->successful(), $answer->status() === 404 && ! $mutating => $answer,
            $answer->clientError() => throw new RegistrarException('Acme refused the request: '.($answer->json('error') ?? "HTTP {$answer->status()}")),
            $mutating => throw new RegistrarTimeout("Acme answered HTTP {$answer->status()}; the outcome is unknown."),
            default => throw new RegistrarException("Acme answered HTTP {$answer->status()}.", retryable: true),
        };
    }

    private function client(RegistrarConfig $config): PendingRequest
    {
        $base = $config->testMode ? 'https://sandbox.acme-registrar.test/v1' : 'https://api.acme-registrar.test/v1';
        $key = (string) $config->setting($config->testMode ? 'sandbox_api_key' : 'api_key', '');

        return Http::baseUrl($base)->withHeaders(['X-Acme-Key' => $key, 'X-Acme-Reseller' => (string) $config->setting('reseller_id', '')])->acceptJson()->connectTimeout(10)->timeout(90);
    }
}
