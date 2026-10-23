<?php

namespace App\Modules\Notifications\Services;

use App\Contracts\Branding\BrandProvider;
use App\Contracts\Notifications\MergeFieldRegistry;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Data\Recipient;
use App\Modules\Notifications\Support\MailDates;
use App\Modules\Notifications\Support\SupportAddresses;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Builds the plain-array context templates render against (spec §4). Entity data comes from the
 * merge-field resolvers other modules register in MergeFieldRegistry; nothing here reads their tables.
 */
class MergeContext
{
    public function __construct(
        private MergeFieldRegistry $registry,
        private BrandProvider $brand,
        private Settings $settings,
    ) {}

    /**
     * company, system and signature — available to every template.
     *
     * @return array<string, mixed>
     */
    public function base(bool $forStaff = false): array
    {
        $brand = $this->brand->brand();

        return [
            'company' => [
                'name' => $brand->name,
                'url' => (string) config('app.url'),
                'logo_url' => $this->brand->emailLogoUrl(),
                'email' => (string) $this->settings->get('core.company_email', config('mail.from.address')),
                'pay_to_text' => (string) $this->settings->get('billing.pay_to_text', ''),
            ],
            'system' => array_filter([
                'client_area_url' => $this->link('client.dashboard'),
                'admin_url' => $forStaff ? $this->link('admin.dashboard') : null,
                'today' => MailDates::companyDate(),
            ], fn (mixed $value): bool => $value !== null),
            'signature' => (string) $this->settings->get('notifications.signature', ''),
            'support' => [
                'email' => app(SupportAddresses::class)->general(),
                'url' => $this->settings->get('notifications.support_url') ?: $this->link('client.support.tickets.index'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recipient(Recipient $recipient): array
    {
        return [
            'recipient' => ['name' => $recipient->name, 'first_name' => $recipient->firstName(), 'email' => $recipient->email],
            ...($recipient->isStaff() ? ['staff' => ['first_name' => $recipient->firstName(), 'email' => $recipient->email]] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function client(?int $clientId): array
    {
        if ($clientId === null) {
            return [];
        }

        $client = Client::query()->find($clientId);

        if ($client === null) {
            return [];
        }

        return ['client' => [
            ...$this->resolve('client', $clientId),
            'id' => $client->id,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'full_name' => trim($client->first_name.' '.$client->last_name),
            'company_name' => $client->company_name,
            'email' => $client->email,
            'currency' => $client->currency,
            'country' => $client->country,
            'language' => $client->language,
            'admin_url' => $this->link('admin.clients.show', $client->id),
        ]];
    }

    /**
     * Entity fields from the module that owns it, or [] when that module is not installed.
     *
     * @return array<string, mixed>
     */
    public function resolve(string $entity, ?int $id): array
    {
        if ($id === null || ! $this->registry->has($entity)) {
            return [];
        }

        try {
            return $this->registry->resolve($entity, $id);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function invoice(?int $invoiceId): array
    {
        $invoice = $this->resolve('invoice', $invoiceId);

        if ($invoice === []) {
            return [];
        }

        $invoice['balance_due'] = strtolower((string) ($invoice['status'] ?? '')) !== 'paid';

        return ['invoice' => $invoice];
    }

    /**
     * @return array<string, mixed>
     */
    public function order(?int $orderId): array
    {
        $order = $this->resolve('order', $orderId);

        return $order === [] ? [] : ['order' => [...$order, 'admin_url' => $this->link('admin.orders.show', $orderId)]];
    }

    /**
     * The service with its client-area link. Credentials are never emailed: welcome mail links to the
     * service page, which shows them after sign-in. Any password a resolver returns is dropped.
     *
     * @return array<string, mixed>
     */
    public function service(?int $serviceId): array
    {
        $service = $this->resolve('service', $serviceId);

        if ($service === []) {
            return [];
        }

        $service['id'] = $service['service_id'] ?? $serviceId;
        $service['admin_url'] = $this->link('admin.services.services.show', $serviceId);
        $service['url'] = $this->link('client.services.show', $serviceId);
        unset($service['password']);

        return ['service' => $service];
    }

    /**
     * @return array<string, mixed>
     */
    public function domain(?int $domainId): array
    {
        $domain = $this->resolve('domain', $domainId);

        return $domain === [] ? [] : ['domain' => [...$domain, 'id' => $domainId, 'renew_url' => $this->link('client.domains.show', $domainId)]];
    }

    /**
     * @return array<string, mixed>
     */
    public function ticket(?int $ticketId, ?int $replyId = null): array
    {
        $ticket = $this->resolve('ticket', $ticketId);

        if ($ticket === []) {
            return [];
        }

        $reply = $this->resolve('ticket_reply', $replyId);

        return ['ticket' => $ticket, 'reply' => $reply === [] ? ['author_name' => '', 'body_html' => '', 'excerpt' => ''] : $reply];
    }

    /**
     * A named route when its module is installed, else null (modules can be disabled per install).
     */
    public function link(string $name, mixed $parameters = []): ?string
    {
        return Route::has($name) ? route($name, $parameters) : null;
    }
}
