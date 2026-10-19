<?php

namespace App\Contracts\Services\Provisioning;

use App\Data\Services\Provisioning\ServerConnection;
use App\Data\Services\Provisioning\SsoLink;

/**
 * Capability: a one-time login link into the server's administration panel as the server's own user (staff only).
 */
interface ProvidesServerSso
{
    public function serverSsoLink(ServerConnection $server): SsoLink;
}
