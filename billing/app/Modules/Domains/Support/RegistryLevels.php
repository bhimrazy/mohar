<?php

namespace App\Modules\Domains\Support;

use App\Contracts\Domains\DomainNameRules;
use App\Modules\Domains\Models\Tld;

/**
 * The one place that knows which country registries issue names only under fixed second levels. Orders reach it
 * through the DomainNameRules contract; the domain search and the registrar actions reach it through
 * Tld::registryLevelHint().
 */
class RegistryLevels implements DomainNameRules
{
    /** Verified 2026-10-20 at register.com.np/np-ccTLDs (the .np registry, run by Mercantile Communications per IANA). */
    public const SECOND_LEVELS = [
        'np' => ['com', 'coop', 'edu', 'gov', 'info', 'mil', 'name', 'net', 'org'],
    ];

    /**
     * The registry's top label and its second levels when $domain breaks its rule, or null.
     *
     * @return array{tld: string, levels: list<string>}|null
     */
    public static function violation(string $domain): ?array
    {
        $labels = explode('.', strtolower(trim($domain, '.')));
        $top = end($labels);
        $levels = self::SECOND_LEVELS[$top] ?? null;

        if ($levels === null || (count($labels) >= 3 && in_array($labels[count($labels) - 2], $levels, true))) {
            return null;
        }

        return ['tld' => $top, 'levels' => $levels];
    }

    /** Short reason for the domain search, or null. */
    public static function hint(string $domain): ?string
    {
        $violation = self::violation($domain);

        if ($violation === null) {
            return null;
        }

        return __('.:tld names are registered under :levels. Search for yourname.com.:tld.', [
            'tld' => $violation['tld'],
            'levels' => self::levelList($violation),
        ]);
    }

    public function registryLevelError(string $domain): ?string
    {
        $violation = self::violation($domain);

        if ($violation === null) {
            return null;
        }

        return __('Enter your full .:tld name, for example yourname.com.:tld. Names are registered under :levels.', [
            'tld' => $violation['tld'],
            'levels' => self::levelList($violation),
        ]);
    }

    public function registeredByClient(string $tld): bool
    {
        return Tld::query()->where('extension', Tld::normaliseExtension($tld))->where('registered_by_client', true)->exists();
    }

    /** @param array{tld: string, levels: list<string>} $violation */
    private static function levelList(array $violation): string
    {
        return implode(', ', array_map(fn (string $level): string => ".{$level}.{$violation['tld']}", $violation['levels']));
    }
}
