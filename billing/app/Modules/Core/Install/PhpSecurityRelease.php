<?php

namespace App\Modules\Core\Install;

/**
 * The oldest PHP patch release per supported minor version that has no known critical vulnerability. Every web SAPI
 * (FrankenPHP, PHP-FPM, Apache mod_php, LiteSpeed) below these releases is open to CVE-2026-109618 and CVE-2026-109619,
 * unauthenticated remote code execution with no mitigation besides upgrading (php.net, 2026-10-21).
 *
 * composer.json keeps "php": "^8.4" on purpose: development machines and CI do not serve public requests and may run
 * older 8.4 builds, so this is reported by the install check and the admin dashboard instead of refusing to boot.
 * deploy/Dockerfile refuses to build a production image below the 8.4 floor (tests/Unit/Deploy keeps them in step).
 */
final class PhpSecurityRelease
{
    /** @var array<string, string> minor version => first patched release */
    public const PATCHED = [
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    /** @var list<string> */
    public const ADVISORIES = ['CVE-2026-109618', 'CVE-2026-109619'];

    public function __construct(private string $version = PHP_VERSION) {}

    public function version(): string
    {
        return $this->version;
    }

    /**
     * The release to upgrade to, or null when this version is patched or its minor version is not listed (a minor the
     * app does not support, or a newer one than this list knows about).
     */
    public function requiredUpgrade(): ?string
    {
        if (! preg_match('/^(\d+\.\d+)\.\d+/', $this->version, $matches) || ! isset(self::PATCHED[$matches[1]])) {
            return null;
        }

        $patched = self::PATCHED[$matches[1]];

        return version_compare($this->version, $patched, '<') ? $patched : null;
    }

    public function vulnerable(): bool
    {
        return $this->requiredUpgrade() !== null;
    }

    /**
     * One sentence for an operator: what is wrong and what to do, or null when nothing is.
     */
    public function problem(): ?string
    {
        $required = $this->requiredUpgrade();

        if ($required === null) {
            return null;
        }

        return "PHP {$this->version} has critical security vulnerabilities (".implode(', ', self::ADVISORIES).', unauthenticated remote code execution). '
            ."Upgrade PHP to {$required} or newer, then restart the web server, queue workers and every other long-running PHP process (docs/devops/install.md).";
    }
}
