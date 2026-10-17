<?php

namespace App\Modules\Services\Support;

use App\Modules\Services\Models\Service;
use Illuminate\Support\Str;

/**
 * Hosting account usernames: lowercase letters/digits taken from the domain, starting with a
 * letter, at most 8 characters including a numeric suffix added on collision (unique per server).
 */
class UsernameGenerator
{
    public const MAX_LENGTH = 8;

    public function __construct(private ServiceSettings $settings) {}

    public function generate(?string $domain, ?int $serverId, ?int $exceptServiceId = null): string
    {
        $base = $this->settings->randomUsernames() ? $this->randomBase() : $this->baseFrom($domain);
        $candidate = substr($base, 0, self::MAX_LENGTH);

        for ($suffix = 1; $this->taken($candidate, $serverId, $exceptServiceId); $suffix++) {
            $candidate = substr($base, 0, self::MAX_LENGTH - strlen((string) $suffix)).$suffix;
        }

        return $candidate;
    }

    public function password(): string
    {
        return Str::password(16, symbols: false);
    }

    private function baseFrom(?string $domain): string
    {
        $label = Str::before(Str::lower((string) $domain), '.');
        $clean = ltrim((string) preg_replace('/[^a-z0-9]/', '', $label), '0123456789');

        return $clean !== '' ? $clean : $this->randomBase();
    }

    private function randomBase(): string
    {
        return chr(random_int(97, 122)).Str::lower(Str::random(self::MAX_LENGTH - 1));
    }

    private function taken(string $username, ?int $serverId, ?int $exceptServiceId): bool
    {
        return Service::withTrashed()
            ->where('username', $username)
            ->when($serverId !== null, fn ($query) => $query->where('server_id', $serverId), fn ($query) => $query->whereNull('server_id'))
            ->when($exceptServiceId !== null, fn ($query) => $query->whereKeyNot($exceptServiceId))
            ->exists();
    }
}
