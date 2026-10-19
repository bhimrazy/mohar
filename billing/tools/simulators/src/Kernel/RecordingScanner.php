<?php

namespace Mohar\Simulators\Kernel;

use App\Support\Integrations\Redactor;

/**
 * The secret scan over `tools/simulators/<provider>/recordings/` (ADR 0016, "Leaked secrets in recordings"): run by
 * `simulate:record` on what it just wrote, by `simulate:scan` (the pre-commit hook), and by the framework tests in CI.
 *
 * A finding is a credential (a known key or token format, an Authorization value, a private key, a value under a
 * sensitive key that is not masked) or personal data (an email outside example domains, a public IP address).
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

        array_walk_recursive($decoded, function (mixed $value, int|string $key) use (&$findings): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            if ($this->redactor->isSensitiveKey($key) && $value !== Redactor::MASK) {
                $findings[] = "an unmasked value under \"{$key}\"";
            }

            preg_match_all('/[A-Z0-9._%+-]+@([A-Z0-9-]+\.)+[A-Z]{2,}/i', $value, $emails);

            foreach ($emails[0] as $email) {
                if (preg_match('/@(.+\.)?(example|test|invalid|localhost)$|@example\.(com|org|net)$/i', $email) !== 1) {
                    $findings[] = "a real-looking email address under \"{$key}\"";
                }
            }

            // Version numbers look like addresses (WHM 11.124.0.12).
            preg_match_all('/\b\d{1,3}(\.\d{1,3}){3}\b/', is_string($key) && stripos($key, 'version') !== false ? '' : $value, $addresses);

            foreach ($addresses[0] as $address) {
                if (self::isPublicIpv4($address)) {
                    $findings[] = "a public IP address under \"{$key}\"";
                }
            }
        });

        return array_values(array_unique($findings));
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
