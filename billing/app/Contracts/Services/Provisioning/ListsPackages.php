<?php

namespace App\Contracts\Services\Provisioning;

use App\Data\Services\Provisioning\RemotePackage;
use App\Data\Services\Provisioning\ServerConnection;

/**
 * Capability: the packages (plans) the server's user can assign, for product config fields whose
 * SettingField::$optionsSource is `remote:packages`. Read-only; the host caches the answer.
 */
interface ListsPackages
{
    /**
     * @return list<RemotePackage>
     */
    public function packages(ServerConnection $server): array;
}
