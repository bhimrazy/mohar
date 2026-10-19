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
    /**
     * The only keys whose string values are kept as they came: WHM's own words (`reason`, `command`), versions,
     * statuses, flags, sizes and limits, ACL names and dates. Every other string is replaced (deny by default).
     */
    private const SAFE_KEYS = [
        'reason', 'command', 'result', 'status', 'statusmsg', 'version', 'suspended', 'is_locked', 'diskused',
        'disklimit', 'quota', 'bwlimit', 'maxaddon', 'maxftp', 'maxlst', 'maxpark', 'maxpop', 'maxsql', 'maxsub',
        'hasshell', 'cgi', 'lang', 'theme', 'mailbox_format', 'acls', 'service', 'app', 'month', 'year', 'limit',
        'startdate', 'expires', 'expires_at', 'create_time', 'unix_startdate', 'backup', 'legacy_backup', 'temporary',
        'outgoing_mail_hold', 'outgoing_mail_suspended', 'max_defer_fail_percentage', 'max_email_per_hour',
    ];

    /**
     * The read-only calls `simulate:record whm` makes, by recording name; the drift test replays the same ones.
     *
     * @return array<string, array{0: string, 1: array<string, scalar>}>
     */
    public static function calls(string $token, string $user, string $account): array
    {
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

        return $calls;
    }

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
        $calls = self::calls($token, $user, $account);

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
     * Same structure and types, placeholder values: a string is kept only under a SAFE_KEYS key or when it is a size or
     * a number ("14M", "unlimited", "0"); every other string becomes a placeholder of its kind (an IPv4 or IPv6
     * address, an email, a URL, a host name, or "example"). Numbers and flags stay as they are.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function anonymise(array $value, ?string $parent = null): array
    {
        foreach ($value as $key => $item) {
            $context = is_string($key) ? strtolower($key) : $parent;

            $value[$key] = match (true) {
                is_array($item) => self::anonymise($item, $context),
                ! is_string($item) || $item === '' => $item,
                in_array($context, self::SAFE_KEYS, true), preg_match('/^(\d+(\.\d+)?[KMGT]?|unlimited)$/i', $item) === 1 => $item,
                filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => '192.0.2.10',
                filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => '2001:db8::10',
                str_contains($item, '@') => 'owner@example.test',
                str_contains($item, '://') => 'https://example.test/',
                preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $item) === 1 => 'host.example.test',
                default => 'example',
            };
        }

        return $value;
    }
}
