<?php

namespace Mohar\Simulators\Kernel;

use App\Support\Integrations\Redactor;

/**
 * The secret scan over `tools/simulators/<provider>/recordings/` (ADR 0016, "Leaked secrets in recordings"): run by
 * `simulate:record` on what it just wrote, by `simulate:scan` (the pre-commit hook), and by the framework tests in CI.
 *
 * A finding is a credential (a known key or token format, an Authorization value, a private key, a cPanel session
 * path, a value under a sensitive key that is not masked) or personal data (an email outside example domains, a public
 * IPv4 or IPv6 address, a host name outside the example and test domains).
 */
final class RecordingScanner
{
    /** Credential formats, by what they are. */
    private const SECRET_PATTERNS = [
        'a WHM authorization value' => '/\bwhm\s+[a-z0-9]+:[A-Z0-9]{20,}/i',
        'an HTTP authorization value' => '/\b(Basic|Bearer)\s+[A-Za-z0-9+\/=._-]{16,}/',
        'a private key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'a Stripe key or secret' => '/\b(sk|rk|pk)_(live|test)_[A-Za-z0-9]{8,}|\bwhsec_[A-Za-z0-9]{8,}/',
        'a cPanel API token' => '/\b[A-Z0-9]{32}\b/',
        'a JSON web token' => '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\./',
        'an AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
        'a cPanel session path' => '/cpsess\d{6,}/',
    ];

    private const NON_PUBLIC_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3',
    ];

    public function __construct(private readonly Redactor $redactor = new Redactor) {}

    /**
     * Every recordings directory under the simulators package.
     *
     * @return list<string> "<file>: <finding>"
     */
    public function scanPackage(): array
    {
        $findings = [];

        foreach (glob(dirname(__DIR__, 2).'/*/recordings', GLOB_ONLYDIR) ?: [] as $directory) {
            array_push($findings, ...$this->scan($directory));
        }

        return $findings;
    }

    /**
     * @return list<string> "<file>: <finding>"
     */
    public function scan(string $directory): array
    {
        $findings = [];

        foreach (glob($directory.'/*') ?: [] as $file) {
            foreach ($this->scanFile($file) as $finding) {
                $findings[] = basename(dirname($file, 2)).'/recordings/'.basename($file).': '.$finding;
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    public function scanFile(string $file): array
    {
        $text = (string) file_get_contents($file);
        $decoded = json_decode($text, true);

        if (! str_ends_with($file, '.json') || ! is_array($decoded) || ! in_array($decoded['source'] ?? null, ['docs', 'live'], true)) {
            return ['not a recording (a JSON file with source "docs" or "live")'];
        }

        $findings = [];

        foreach (self::SECRET_PATTERNS as $what => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $findings[] = "contains {$what}";
            }
        }

        // The response only (the stored shape holds type names under the same keys): every key and every value.
        $this->walk((array) ($decoded['response'] ?? []), $findings);

        return array_values(array_unique($findings));
    }

    /**
     * Keys are checked like values: customer data can be a key too (`{"alpha-books.com": ...}`, metadata keys).
     *
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $findings
     */
    private function walk(array $values, array &$findings): void
    {
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $this->personalData($key, 'a key', $findings);
            }

            if (is_array($value)) {
                $this->walk($value, $findings);
            } elseif (is_string($value) && $value !== '') {
                if ($this->redactor->isSensitiveKey($key) && $value !== Redactor::MASK) {
                    $findings[] = "an unmasked value under \"{$key}\"";
                }

                // A provider's object type names look like host names (Stripe's `checkout.session`); they name no one.
                if ($key === 'object') {
                    continue;
                }

                // Version numbers look like addresses (WHM 11.124.0.12).
                $this->personalData(is_string($key) && stripos($key, 'version') !== false ? (string) preg_replace('/\b\d{1,3}(\.\d{1,3}){3}\b/', '', $value) : $value, "under \"{$key}\"", $findings);
            }
        }
    }

    /**
     * @param  list<string>  $findings
     */
    private function personalData(string $text, string $where, array &$findings): void
    {
        preg_match_all('/[A-Z0-9._%+-]+@([A-Z0-9-]+\.)+[A-Z]{2,}/i', $text, $emails);

        foreach ($emails[0] as $email) {
            if (preg_match('/@(.+\.)?(example|test|invalid|localhost)$|@example\.(com|org|net)$/i', $email) !== 1) {
                $findings[] = "a real-looking email address {$where}";
            }
        }

        preg_match_all('/\b\d{1,3}(\.\d{1,3}){3}\b/', $text, $addresses);

        foreach ($addresses[0] as $address) {
            if (self::isPublicIpv4($address)) {
                $findings[] = "a public IP address {$where}";
            }
        }

        preg_match_all('/(?<![0-9a-f:])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?![0-9a-f:])/i', $text, $ipv6);

        foreach ($ipv6[0] as $address) {
            // Global unicast (2000::/3) outside the documentation prefix 2001:db8::/32.
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && preg_match('/^[23][0-9a-f]{0,3}:/i', $address) === 1 && preg_match('/^2001:0?db8:/i', $address) !== 1) {
                $findings[] = "a public IPv6 address {$where}";
            }
        }

        preg_match_all('/(?<![@\w.-])(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}(?![\w-])/i', $text, $hosts);

        foreach ($hosts[0] as $host) {
            if (preg_match('/(^|\.)(test|example|invalid|localhost)$|(^|\.)example\.(com|org|net)$/i', $host) !== 1) {
                $findings[] = "a real-looking host name {$where}";
            }
        }
    }

    /**
     * Not private, loopback, link-local, shared, multicast, reserved or a documentation range (RFC 5737).
     */
    private static function isPublicIpv4(string $address): bool
    {
        $long = ip2long($address);

        if ($long === false) {
            return false;
        }

        foreach (self::NON_PUBLIC_RANGES as $range) {
            [$network, $bits] = explode('/', $range);
            $mask = -1 << (32 - (int) $bits);

            if ((($long ^ (int) ip2long($network)) & $mask) === 0) {
                return false;
            }
        }

        return true;
    }
}
