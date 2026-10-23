<?php

namespace App\Modules\Core\Support;

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Support\Dashboard\Dashboard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

/**
 * Core's admin dashboard widgets (clients). Registered from CoreServiceProvider::bootModule().
 */
class CoreDashboard
{
    public function register(Dashboard $dashboard): void
    {
        $dashboard->card('admin', 'core.clients', fn (?User $user): array => $this->activeClients(), 'core.clients.view', 10);
        $dashboard->card('admin', 'core.clients.new', fn (?User $user): array => $this->newClients(), 'core.clients.view', 11);
        $dashboard->widget('admin', 'core.clients.newest', 'list', fn (?User $user): array => $this->newestClients(), 'core.clients.view', 55, 'md');
        $dashboard->todo('admin', 'core.timezone.invalid', fn (): array => [
            'label' => 'Company time zone is not valid: dates use UTC',
            'count' => app(CoreCompanyClock::class)->isFallback() ? 1 : 0,
            'href' => Route::has('admin.platform.settings.edit') ? route('admin.platform.settings.edit', 'company') : route('admin.dashboard'),
            'tone' => 'danger',
            'icon' => 'Clock',
        ], 'core.settings.update', 5);
        $dashboard->widget('admin', 'core.clients.signups', 'chart', fn (?User $user): array => $this->signups(), 'core.clients.view', 35, 'md', deferred: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function activeClients(): array
    {
        $added = Client::query()->where('status', 'active')->where('created_at', '>=', CarbonImmutable::now()->startOfMonth())->count();

        return [
            'label' => 'Active clients',
            'value' => Client::query()->where('status', 'active')->count(),
            'hint' => trans_choice('{0} none new this month|{1} 1 new this month|[2,*] :count new this month', $added),
            'href' => route('admin.clients.index', ['status' => 'active']),
            'icon' => 'Users',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newClients(): array
    {
        $start = CarbonImmutable::now()->startOfMonth();
        $thisMonth = Client::query()->where('created_at', '>=', $start)->count();
        $lastMonth = Client::query()->where('created_at', '>=', $start->subMonth())->where('created_at', '<', $start)->count();

        return [
            'label' => 'New clients this month',
            'value' => $thisMonth,
            'trend' => [
                'value' => $lastMonth === 0 ? ($thisMonth === 0 ? '0' : '+'.$thisMonth) : round(($thisMonth - $lastMonth) / $lastMonth * 100).'%',
                'direction' => match ($thisMonth <=> $lastMonth) {
                    1 => 'up',
                    0 => 'flat',
                    default => 'down',
                },
            ],
            'hint' => 'vs. last month',
            'href' => route('admin.clients.index'),
            'icon' => 'UserPlus',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newestClients(): array
    {
        return [
            'title' => 'Newest clients',
            'href' => route('admin.clients.index'),
            'empty' => 'New sign-ups appear here.',
            'items' => Client::query()->latest('id')->limit(5)->get()->map(fn (Client $client): array => [
                'title' => $client->display_name,
                'subtitle' => $client->email,
                'avatar' => $client->display_name,
                'meta' => $client->created_at?->toIso8601String(),
                'status' => $client->status,
                'href' => route('admin.clients.show', $client),
            ])->all(),
        ];
    }

    /**
     * New clients per week for the last 12 weeks, in the company's calendar (counted per local day in SQL, then
     * bucketed into weeks).
     *
     * @return array<string, mixed>
     */
    private function signups(): array
    {
        $calendar = app(CoreCompanyClock::class);
        $start = $calendar->today()->startOfWeek()->subWeeks(11);
        $weeks = [];

        for ($week = 0; $week < 12; $week++) {
            $weeks[$start->addWeeks($week)->toDateString()] = 0;
        }

        Client::query()->toBase()
            ->where('created_at', '>=', $calendar->dayBounds($start)[0])
            ->selectRaw($calendar->sqlLocalDate('created_at').' as day, count(*) as total', [$calendar->sqlZoneArgument()])
            ->groupByRaw('1')
            ->get()
            ->each(function (object $row) use (&$weeks): void {
                $key = CarbonImmutable::parse(substr((string) $row->day, 0, 10))->startOfWeek()->toDateString();

                if (array_key_exists($key, $weeks)) {
                    $weeks[$key] += (int) $row->total;
                }
            });

        return [
            'title' => 'New clients',
            'description' => 'Per week, last 12 weeks · '.trans_choice('{0} none|{1} 1 new client|[2,*] :count new clients', array_sum($weeks)),
            'xFormat' => 'week',
            'empty' => 'No new clients in the last 12 weeks.',
            'kind' => 'bar',
            'href' => route('admin.clients.index'),
            'series' => [[
                'name' => 'New clients',
                'points' => array_map(fn (string $x, int $y): array => ['x' => $x, 'y' => $y], array_keys($weeks), array_values($weeks)),
            ]],
        ];
    }
}
