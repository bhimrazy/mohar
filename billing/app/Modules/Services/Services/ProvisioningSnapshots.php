<?php

namespace App\Modules\Services\Services;

use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Data\Catalog\ProductSummary;
use App\Data\Integrations\SettingField;
use App\Data\Services\Provisioning\ClientSummary;
use App\Data\Services\Provisioning\PreviousPackage;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Data\Services\Provisioning\ServerConnection;
use App\Data\Services\Provisioning\ServiceSnapshot;
use App\Enums\BillingCycle;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\Catalog;

/**
 * Builds the read-only snapshots drivers receive (ADR 0009 §2). Everything a driver may know about a service is
 * chosen here.
 */
class ProvisioningSnapshots
{
    public function __construct(private Catalog $catalog) {}

    public function context(Service $service, ?Server $server, ?ProductSummary $product, ProvisioningDriver $driver): ProvisioningContext
    {
        $productConfig = $product->driverConfig ?? [];
        $inputs = $this->catalog->moduleInputs($service->product_id, $service->config_options['selections'] ?? [], $service->custom_fields ?? []);

        return new ProvisioningContext(
            service: $this->service($service),
            server: $server === null ? null : $this->connection($server),
            client: new ClientSummary($service->client->id, $service->client->display_name, $service->client->email, $service->client->country),
            productConfig: $productConfig,
            configOptions: $inputs->configOptions,
            customFields: $inputs->customFields,
            secretValues: [...$this->secretSettingValues($driver->metadata()->productConfigFields, $productConfig), ...$inputs->secretValues],
        );
    }

    public function connection(Server $server): ServerConnection
    {
        return new ServerConnection(
            id: $server->id,
            name: $server->name,
            hostname: $server->hostname,
            ip: $server->ip_address,
            port: $server->port,
            secure: $server->secure,
            username: $server->username,
            password: $server->password,
            apiToken: $server->api_token,
            accessHash: $server->access_hash,
            nameservers: $server->nameservers ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $previous  the service change's from-snapshot (product_id, cycle, ...)
     */
    public function previousPackage(array $previous): PreviousPackage
    {
        $productId = isset($previous['product_id']) ? (int) $previous['product_id'] : null;

        return new PreviousPackage(
            productId: $productId,
            billingCycle: isset($previous['cycle']) ? BillingCycle::tryFrom((string) $previous['cycle']) : null,
            productConfig: $productId === null ? [] : ($this->catalog->product($productId)->driverConfig ?? []),
        );
    }

    private function service(Service $service): ServiceSnapshot
    {
        return new ServiceSnapshot(
            id: $service->id,
            clientId: $service->client_id,
            productId: $service->product_id,
            status: $service->status->value,
            domain: $service->domain,
            username: $service->username,
            password: $service->password,
            dedicatedIp: $service->dedicated_ip,
            assignedIps: $service->assigned_ips ?? [],
            driverData: $service->driver_data ?? [],
            billingCycle: $service->cycle,
            nextDueDate: $service->next_due_date?->toDateString(),
        );
    }

    /**
     * @param  list<SettingField>  $fields
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function secretSettingValues(array $fields, array $values): array
    {
        $secrets = [];

        foreach ($fields as $field) {
            if ($field->isSecret() && is_scalar($values[$field->key] ?? null) && (string) $values[$field->key] !== '') {
                $secrets[] = (string) $values[$field->key];
            }
        }

        return $secrets;
    }
}
