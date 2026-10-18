<?php

namespace App\Contracts\Services\Provisioning;

use App\Contracts\Integrations\IntegrationDriver;
use App\Data\Services\Provisioning\DriverMetadata;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Support\Services\RetryableProvisioningException;

/**
 * Core contract of a provisioning driver: a control panel, hypervisor or licence system, or manual setup.
 * Provider contract (ADR 0010): never add a method here; add a capability interface in this namespace instead
 * (ADR 0012). Register a driver with ProvisioningDriverRegistry::register().
 *
 * Drivers are stateless. Every call receives a ProvisioningContext snapshot; a driver never reads or writes the
 * host's tables and reports changes only through DriverResult::$data. Constructors stay cheap: no HTTP, no queries,
 * no decryption.
 *
 * Every call maps the remote answer to exactly one outcome (App\Enums\DriverOutcome):
 * - completed: DriverResult::ok();
 * - rejected: nothing was executed and retrying will not help, DriverResult::fail();
 * - retryable: provably not executed (connection refused, panel busy), DriverResult::fail(retryable: true) or a
 *   RetryableProvisioningException. The host retries with backoff;
 * - unknown: the request may have run (read timeout after sending, unparseable answer), DriverResult::unknown().
 *   The host never retries it and opens a "verify" item for staff. Any other exception a driver throws during a
 *   call is treated the same way, so return fail() for errors you know happened before anything was sent.
 *
 * Make calls idempotent where the remote system allows it: creating an account that already exists for the
 * same owner is a success. Keep HTTP timeouts below the command timeout: DriverMetadata::$commandTimeoutSeconds, or
 * the host default (services.module.job_timeout_seconds, 60 s) when the driver declares none. Connect to staff-entered
 * hosts through App\Support\Integrations\OutboundAddressGuard.
 */
interface ProvisioningDriver extends IntegrationDriver
{
    public function metadata(): DriverMetadata;

    /**
     * Create the account. A successful result may carry `username`, `password`, `dedicated_ip` and `driver_data`
     * in its data; the host stores them on the service.
     *
     * @throws RetryableProvisioningException
     */
    public function create(ProvisioningContext $context): DriverResult;

    /**
     * @param  string  $reason  why the service is suspended, as readable text the host builds and the panel records
     *                          (e.g. "Overdue invoice")
     *
     * @throws RetryableProvisioningException
     */
    public function suspend(ProvisioningContext $context, string $reason): DriverResult;

    /**
     * @throws RetryableProvisioningException
     */
    public function unsuspend(ProvisioningContext $context): DriverResult;

    /**
     * @throws RetryableProvisioningException
     */
    public function terminate(ProvisioningContext $context): DriverResult;
}
