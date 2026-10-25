<?php

namespace App\Modules\Services\Support;

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Support\Dashboard\Dashboard;

/**
 * Services' part of the admin client page ("admin-client" dashboard area): counts by status and the next renewals.
 */
class ServicesAdminClientSummary
{
    private const RECENT = 5;

    public function register(Dashboard $dashboard): void
    {
        $dashboard->card('admin-client', 'services.count', function (?User $user, Client $client): array {
            $counts = Service::query()->where('client_id', $client->id)->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status');
            $hint = collect([ServiceStatus::Suspended, ServiceStatus::Pending])
                ->filter(fn (ServiceStatus $status): bool => (int) ($counts[$status->value] ?? 0) > 0)
                ->map(fn (ServiceStatus $status): string => $counts[$status->value].' '.$status->value)->implode(' · ');

            return [
                'label' => 'Active services',
                'value' => (int) ($counts[ServiceStatus::Active->value] ?? 0),
                'hint' => $hint === '' ? null : $hint,
                'href' => route('admin.services.index', ['client' => $client->id]),
                'icon' => 'Server',
            ];
        }, 'services.services.view', 21);

        $dashboard->widget('admin-client', 'services.recent', 'list', fn (?User $user, Client $client): array => [
            'title' => 'Services',
            // What is live or needs a decision first, then the next renewal.
            'items' => Service::query()->where('client_id', $client->id)
                ->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value, ServiceStatus::Pending->value])
                ->orderByRaw('next_due_date is null')->orderBy('next_due_date')->orderBy('id')
                ->limit(self::RECENT)
                ->get(['id', 'product_name', 'domain', 'status', 'recurring_amount', 'currency', 'next_due_date'])
                ->map(fn (Service $service): array => [
                    'title' => $service->product_name ?? 'Service',
                    'subtitle' => $service->domain,
                    'href' => route('admin.services.services.show', $service),
                    'money' => ['amount' => $service->recurring_amount, 'currency' => $service->currency],
                    'status' => $service->status->value,
                    'date' => $service->status === ServiceStatus::Pending ? null : $service->next_due_date?->toDateString(),
                    'dateLabel' => 'Next due',
                ])->values()->all(),
            'href' => route('admin.services.index', ['client' => $client->id]),
            'empty' => 'No services yet.',
        ], 'services.services.view', 31);
    }
}
