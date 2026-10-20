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

    /** Safe keys whose values are WHM's free text, scrubbed of anything that could name a customer. */
    private const FREE_TEXT_KEYS = ['reason', 'statusmsg'];

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

            $recorder->record($name, self::anonymise($decoded), 'live', [$token, $host, $user, $account], ['login' => $user === 'root' ? 'root' : 'reseller']);
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
     * Same structure and types, placeholder values, deny by default:
     *
     * - a key is kept only when it looks like WHM's own (`basic-whm-functions`, `diskused`); a key that could be data (a
     *   domain, an address, anything with a dot, @ or space) is replaced by `key1`, `key2`, ...;
     * - a string is kept only under a SAFE_KEYS key; in free text (a reason) any email, URL, address or host name is
     *   replaced, since WHM's reason may name a customer's domain;
     * - every other string becomes a placeholder of its kind: an IPv4 or IPv6 address, an email, a URL, a host name,
     *   `0` for a numeric string, or `example`. Numbers and flags stay as they are.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function anonymise(array $value, ?string $parent = null): array
    {
        $result = [];
        $renamed = 0;

        foreach ($value as $key => $item) {
            $safeKey = is_int($key) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/i', $key) === 1;
            $context = is_string($key) && $safeKey ? strtolower($key) : $parent;
            $name = $safeKey ? $key : 'key'.(++$renamed);

            $result[$name] = match (true) {
                is_array($item) => self::anonymise($item, $context),
                ! is_string($item) || $item === '' => $item,
                in_array($context, self::FREE_TEXT_KEYS, true) => self::scrub($item),
                in_array($context, self::SAFE_KEYS, true) => $item,
                filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => '192.0.2.10',
                filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => '2001:db8::10',
                str_contains($item, '@') => 'owner@example.test',
                str_contains($item, '://') => 'https://example.test/',
                preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $item) === 1 => 'host.example.test',
                ctype_digit($item) => '0',
                default => 'example',
            };
        }

        return $result;
    }

    /**
     * WHM's free text (a reason) with what could name a customer replaced, in one pass: emails, URLs, IPv6 and IPv4
     * addresses, host names.
     */
    private static function scrub(string $text): string
    {
        $pattern = '/(?<email>[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})'
            .'|(?<url>[a-z][a-z0-9+.-]*:\/\/[^\s"\'<>]+)'
            .'|(?<ipv6>\[?(?:[0-9a-f]{0,4}:){2,7}[0-9a-f.]{0,15}\]?)'
            .'|(?<ipv4>\b\d{1,3}(?:\.\d{1,3}){3}\b)'
            .'|(?<host>\b(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}\b)/i';

        return (string) preg_replace_callback($pattern, fn (array $match): string => match (true) {
            $match['email'] !== '' => 'owner@example.test',
            $match['url'] !== '' => 'https://example.test/',
            $match['ipv6'] !== '' => '2001:db8::10',
            $match['ipv4'] !== '' => '192.0.2.10',
            default => 'host.example.test',
        }, $text);
    }
}
