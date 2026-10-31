<?php

namespace App\Modules\Orders\Boot;

use App\Modules\Orders\Fraud\FraudScreening;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Contracts\Container\Container;

final class Settings
{
    public function __invoke(Container $app): void
    {
        $app->make(SettingsRegistry::class)->section('orders', 'Orders', [
            new SettingDefinition('orders.require_tos', 'Require terms of service acceptance at checkout', 'boolean'),
            new SettingDefinition('orders.tos_url', 'Terms of service URL', 'string', rules: ['nullable', 'url', 'max:255']),
            new SettingDefinition('orders.checkout_redirect', 'After checkout, send customers to', 'select', options: ['complete_page' => 'Order confirmation page', 'invoice' => 'Invoice']),
            new SettingDefinition('orders.initial_invoice_grace_days', 'Days until the first invoice is due', 'integer', rules: ['min:0', 'max:90']),
            new SettingDefinition('orders.cart_ttl_days', 'Days a cart is kept', 'integer', rules: ['min:1', 'max:365']),
            new SettingDefinition('orders.allow_checkout_notes', 'Let customers add notes at checkout', 'boolean'),
            new SettingDefinition('orders.block_duplicate_domains', 'Block ordering hosting for a domain already in use', 'boolean'),
            new SettingDefinition('orders.transfer_requires_epp', 'Require an EPP code for domain transfers', 'boolean'),
            new SettingDefinition('orders.domains_taxable', 'Charge tax on domains', 'boolean'),
            new SettingDefinition('orders.auto_accept_on_payment', 'Accept orders automatically when paid and the fraud check passed', 'boolean'),
            new SettingDefinition('orders.auto_provision_only_existing_clients', 'Only auto-provision for clients with an active service', 'boolean'),
            new SettingDefinition('orders.auto_cancel_unpaid_days', 'Cancel pending unpaid orders after N days (0 = never)', 'integer', rules: ['min:0', 'max:365']),
            new SettingDefinition('orders.suppress_invoice_email_on_order', 'Do not email the invoice for new orders', 'boolean'),
            new SettingDefinition('orders.fraud.driver', 'Fraud check provider', 'select', options: FraudScreening::LABELS),
            new SettingDefinition('orders.fraud.max_pending_orders', 'Maximum pending orders per client', 'integer', rules: ['min:0']),
            new SettingDefinition('orders.fraud.max_orders_per_ip', 'Maximum orders per IP address in 24 hours', 'integer', rules: ['min:0']),
            new SettingDefinition('orders.fraud.banned_email_domains', 'Banned email domains', 'text', 'Comma separated, e.g. mailinator.com'),
            new SettingDefinition('orders.fraud.skip_for_existing_clients', 'Skip fraud checks for clients with an active service', 'boolean'),
            new SettingDefinition('orders.fraud.fail_open', 'Treat fraud provider errors as "review" instead of "fail"', 'boolean'),
            new SettingDefinition('orders.fraud.timeout_ms', 'Fraud check timeout (ms)', 'integer', rules: ['min:100', 'max:60000']),
        ], 'orders.fraud.configure');
    }
}
