<?php

namespace App\Modules\Services\Support;

use App\Data\Services\Provisioning\UsernamePolicy;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Hosting account usernames: lowercase letters/digits taken from the domain, starting with a letter, with a numeric
 * suffix added on collision (unique per server). The driver's UsernamePolicy decides the length and the reserved
 * names, prefixes and suffixes; without one the host default applies (8 characters).
 */
class UsernameGenerator
{
    /** Suffixes tried on one base before a random base is used. */
    private const SUFFIX_ATTEMPTS = 100;

    public function __construct(private ServiceSettings $settings) {}

    public function generate(?string $domain, ?int $serverId, ?int $exceptServiceId = null, ?UsernamePolicy $policy = null): string
    {
        $policy ??= new UsernamePolicy;
        $base = $this->settings->randomUsernames() ? $this->randomBase($policy->maxLength) : $this->baseFrom($domain, $policy->maxLength);

        for ($round = 0; $round < 10; $round++) {
            $candidate = substr($base, 0, $policy->maxLength);

            for ($suffix = 1; $suffix <= self::SUFFIX_ATTEMPTS; $suffix++) {
                if ($policy->violation($candidate) === null && ! $this->taken($candidate, $serverId, $exceptServiceId)) {
                    return $candidate;
                }

                $candidate = substr($base, 0, $policy->maxLength - strlen((string) $suffix)).$suffix;
            }

            $base = $this->randomBase($policy->maxLength);
        }

        throw new RuntimeException('No free username fits the driver\'s username policy.');
    }

    /**
     * 16 characters with at least one lowercase, uppercase, digit and symbol.
     */
    public function password(): string
    {
        $sets = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!#%+-=?@_'];
        $all = implode('', $sets);
        $characters = array_map(fn (string $set): string => $set[random_int(0, strlen($set) - 1)], $sets);

        while (count($characters) < 16) {
            $characters[] = $all[random_int(0, strlen($all) - 1)];
        }

        shuffle($characters);

        return implode('', $characters);
    }

    private function baseFrom(?string $domain, int $length): string
    {
        $label = Str::before(Str::lower((string) $domain), '.');
        $clean = ltrim((string) preg_replace('/[^a-z0-9]/', '', $label), '0123456789');

        return $clean !== '' ? $clean : $this->randomBase($length);
    }

    private function randomBase(int $length): string
    {
        return chr(random_int(97, 122)).Str::lower(Str::random(max(1, min($length, 8) - 1)));
    }

    private function taken(string $username, ?int $serverId, ?int $exceptServiceId): bool
    {
        // Only services that hold an account count (the unique index covers the same set); a terminated service's
        // name is free again, and the server's own check is the final word.
        return Service::query()
            ->whereIn('status', ServiceStatus::occupyingValues())
            ->where('username', $username)
            ->when($serverId !== null, fn ($query) => $query->where('server_id', $serverId), fn ($query) => $query->whereNull('server_id'))
            ->when($exceptServiceId !== null, fn ($query) => $query->whereKeyNot($exceptServiceId))
            ->exists();
    }
}
