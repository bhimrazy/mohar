<?php

namespace Mohar\Simulators\Providers\Whm;

use App\Support\Integrations\OutboundAddressGuard;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Recorder;
use RuntimeException;

/**
 * `simulate:record whm`: read-only WHM API 1 calls against a real server, recorded as `source: live` so the
 * simulator's shapes can be checked against it (ADR 0016 §1, Recorder). Account data is replaced by placeholders of
 * the same type before the recorder redacts secrets, so a recording names no real customer, domain or address.
 */
final class LiveRecording
{
    /** Keys whose string values describe a customer or the server, and are replaced before recording. */
    private const PERSONAL_KEYS = [
        'user', 'owner', 'reseller', 'email', 'domain', 'maindomain', 'ip', 'suspendreason', 'name', 'nameservers',
        'whitelist_ips', 'session', 'cp_security_token', 'url', 'theme', 'partition', 'shell',
    ];

    public function run(Recorder $recorder): void
    {
        $host = self::env('WHM_RECORD_HOST');
        $token = self::env('WHM_RECORD_TOKEN');

        if ($host === '' || $token === '') {
            throw new RuntimeException('Set WHM_RECORD_HOST and WHM_RECORD_TOKEN (and optionally WHM_RECORD_PORT, WHM_RECORD_USER, WHM_RECORD_ACCOUNT, WHM_RECORD_CA_BUNDLE) to record a real WHM.');
        }

        $port = (int) self::env('WHM_RECORD_PORT', '2087');
        $user = self::env('WHM_RECORD_USER', 'root');
        $account = self::env('WHM_RECORD_ACCOUNT');
        $calls = [
            'version' => ['version', []],
            'myprivs' => ['myprivs', []],
            'api_token_get_details' => ['api_token_get_details', ['token' => $token]],
            'listpkgs' => ['listpkgs', ['want' => 'creatable']],
            'listaccts_page1' => ['listaccts', ['api.chunk.enable' => 1, 'api.chunk.size' => 2, 'api.chunk.start' => 1]],
            'accountsummary_missing' => ['accountsummary', ['user' => 'zzsimnone']],
            'showbw' => ['showbw', ['month' => (int) date('n'), 'year' => (int) date('Y')]],
            'get_nameserver_config' => ['get_nameserver_config', []],
            'verify_new_username_ok' => ['verify_new_username', ['user' => 'zzsimprobe']],
        ];

        if ($account !== '') {
            $calls['accountsummary'] = ['accountsummary', ['user' => $account]];
        }

        if ($user !== 'root') {
            $calls['acctcounts'] = ['acctcounts', ['user' => $user]];
        }

        $guard = app(OutboundAddressGuard::class);
        $address = $guard->resolve($host, $port);
        $bundle = self::env('WHM_RECORD_CA_BUNDLE');

        foreach ($calls as $name => [$function, $params]) {
            // Reads only; POST for the token lookup so the token stays out of the server's access log.
            $request = $guard->pin(Http::acceptJson()->timeout(30)->withOptions(['verify' => $bundle !== '' ? $bundle : true])->withHeaders(['Authorization' => "whm {$user}:{$token}"]), $address);
            $url = "https://{$host}:{$port}/json-api/{$function}";
            $response = $function === 'api_token_get_details' ? $request->asForm()->post($url.'?api.version=1', $params) : $request->get($url, ['api.version' => 1, ...$params]);
            $decoded = $response->json();

            if (! is_array($decoded)) {
                throw new RuntimeException("{$function} answered HTTP {$response->status()} without a WHM API 1 body; nothing more was recorded.");
            }

            $recorder->record($name, self::anonymise($decoded), 'live', [$token, $host, $user, $account]);
        }
    }

    /**
     * A variable from the process environment or the loaded .env (never config: these are a developer's credentials
     * for one recording run, not app settings).
     */
    private static function env(string $name, string $default = ''): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Same structure and types, placeholder values: strings under personal keys become `example` values of the same
     * kind (an address stays an address), numbers and flags stay as they are.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function anonymise(array $value, bool $personal = false): array
    {
        foreach ($value as $key => $item) {
            $isPersonal = $personal || (is_string($key) && in_array(strtolower($key), self::PERSONAL_KEYS, true));

            $value[$key] = match (true) {
                is_array($item) => self::anonymise($item, $isPersonal),
                $isPersonal && is_string($item) => match (true) {
                    filter_var($item, FILTER_VALIDATE_IP) !== false => '192.0.2.10',
                    str_contains($item, '@') => 'owner@example.test',
                    str_contains($item, '://') => 'https://example.test/',
                    default => $item === '' ? '' : 'example',
                },
                default => $item,
            };
        }

        return $value;
    }
}
