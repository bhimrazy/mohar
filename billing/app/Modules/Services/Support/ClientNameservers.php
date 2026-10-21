<?php

namespace App\Modules\Services\Support;

use App\Modules\Core\Support\SettingList;
use App\Modules\Core\Support\Settings;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;

/**
 * The nameservers a client points a domain to: the service's server's, else the default nameservers an admin saved
 * (`domains.default_nameservers`, read the way Domains reads it). The shipped placeholder default is never shown.
 */
final class ClientNameservers
{
    public function __construct(private Settings $settings) {}

    /**
     * @return list<string>
     */
    public function forService(Service $service): array
    {
        $server = $this->normalise($service->server->nameservers ?? []);

        // Without a domain there is nothing to point, so only the server's own list is kept (as before).
        return $server !== [] || $service->domain === null ? $server : $this->savedDefault();
    }

    /**
     * Whether any real nameservers exist for clients to use: on a server, or saved as the default.
     */
    public function configured(): bool
    {
        return $this->savedDefault() !== []
            || Server::query()->whereNotNull('nameservers')->orderBy('id')->pluck('nameservers')->contains(fn (mixed $list): bool => $this->normalise($list) !== []);
    }

    /**
     * @return list<string>
     */
    private function savedDefault(): array
    {
        $saved = $this->normalise($this->settings->all()['domains.default_nameservers'] ?? []);

        // Saving the settings form unchanged stores the shipped placeholder: that is not a real value.
        return $saved === $this->normalise(config('domains.default_nameservers', [])) ? [] : $saved;
    }

    /**
     * @return list<string>
     */
    private function normalise(mixed $list): array
    {
        return array_map(strtolower(...), SettingList::of($list));
    }
}
