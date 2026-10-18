<?php

namespace App\Modules\Services\Support;

use App\Enums\BillingCycle;
use App\Models\User;
use App\Modules\Core\Support\CurrencyRollup;
use App\Modules\Services\Enums\QueueStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Support\Dashboard\Dashboard;
use App\Support\Dashboard\Trend;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Services' admin dashboard KPI (monthly recurring revenue) and the provisioning health check.
 */
class ServicesDashboard
{
    /** Month-end points on the MRR sparkline. */
    private const MONTHS = 12;

    /** Provisioning success rate below which the health check warns. */
    private const HEALTHY_RATE = 98.0;

    public function register(Dashboard $dashboard): void
    {
        $dashboard->card('admin', 'services.mrr', fn (?User $user): array => $this->mrr(), 'services.services.view', 1);
        $dashboard->health('admin', 'services.provisioning', fn (?User $user): array => $this->provisioningHealth(), 'services.module_log.view', 30);
    }

    /**
     * Monthly recurring revenue: active and suspended services' recurring amounts per month of their cycle. History
     * counts a service from its registration date until it was terminated or cancelled.
     *
     * @return array<string, mixed>
     */
    private function mrr(): array
    {
        $rollup = new CurrencyRollup;
        $today = CarbonImmutable::today();
        $points = [];

        for ($month = self::MONTHS - 1; $month >= 1; $month--) {
            $points[] = $today->subMonths($month)->endOfMonth()->toDateString();
        }

        $points[] = $today->toDateString();
        $monthAgo = $today->subMonth()->toDateString();
        $series = [];
        $previous = [];
        $count = [];
        $open = [ServiceStatus::Active->value, ServiceStatus::Suspended->value];

        DB::table('services')
            ->whereNull('deleted_at')
            ->whereIn('status', [...$open, ServiceStatus::Terminated->value, ServiceStatus::Cancelled->value])
            ->where('recurring_amount', '>', 0)
            ->where(fn ($query) => $query->whereIn('status', $open)->orWhere('updated_at', '>=', $today->subMonths(self::MONTHS)->startOfMonth()))
            ->get(['currency', 'cycle', 'recurring_amount', 'registration_date', 'termination_date', 'status', 'updated_at'])
            ->each(function (object $row) use (&$series, &$previous, &$count, $points, $monthAgo, $open): void {
                $months = BillingCycle::tryFrom((string) $row->cycle)?->months();

                if ($months === null) {
                    return;
                }

                $code = (string) $row->currency;
                $monthly = (int) round((int) $row->recurring_amount / $months);
                $isOpen = in_array($row->status, $open, true);
                $registered = substr((string) $row->registration_date, 0, 10);
                $ended = $isOpen ? null : substr((string) ($row->termination_date ?? $row->updated_at), 0, 10);
                $activeOn = fn (string $day): bool => $registered <= $day && ($ended === null || $ended > $day);
                $series[$code] ??= array_fill(0, count($points), 0);
                $previous[$code] ??= 0;
                $count[$code] ??= 0;

                foreach ($points as $index => $day) {
                    // The last point is today's exact figure: open services only.
                    $counts = $index === count($points) - 1 ? $isOpen : $activeOn($day);
                    $series[$code][$index] += $counts ? $monthly : 0;
                }

                $previous[$code] += $activeOn($monthAgo) ? $monthly : 0;
                $count[$code] += $isOpen ? 1 : 0;
            });

        $card = fn (array $points, int $before, int $services, string $currency): array => [
            'value' => $rollup->format((int) end($points), $currency),
            'money' => ['amount' => (int) end($points), 'currency' => $currency],
            'sparkline' => $points,
            'hint' => trans_choice('{0} No recurring services|{1} From 1 recurring service|[2,*] From :count recurring services', $services),
            ...array_filter(['trend' => Trend::percent((int) end($points), $before)]),
        ];
        $codes = $rollup->order(array_keys($series));
        $variants = [];

        foreach ($codes as $code) {
            $variants[$code] = $card($series[$code], $previous[$code], $count[$code], $code);
        }

        return [
            'label' => 'Monthly recurring revenue',
            ...$card(
                $series === [] ? array_fill(0, count($points), 0) : $rollup->totalSeries($series, count($points)),
                $rollup->total($previous),
                array_sum($count),
                $rollup->defaultCode(),
            ),
            'href' => route('admin.services.index', ['status' => 'active']),
            'icon' => 'Repeat',
            'variants' => $variants === [] ? (object) [] : $variants,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function provisioningHealth(): array
    {
        $commands = DB::table('module_commands')->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('success, count(*) as total')->groupBy('success')->pluck('total', 'success');
        $succeeded = (int) ($commands[1] ?? $commands['1'] ?? 0);
        $failed = (int) ($commands[0] ?? $commands['0'] ?? 0);
        $total = $succeeded + $failed;
        $open = ModuleQueueItem::query()->where('status', QueueStatus::Open->value)->count();
        $rate = $total === 0 ? null : round($succeeded / $total * 100, 1);

        return [
            'label' => 'Provisioning',
            'detail' => ($total === 0 ? 'No module commands in the last 7 days' : sprintf('%d of %d commands succeeded (7 days)', $succeeded, $total))
                .($open > 0 ? " · {$open} in the retry queue" : ''),
            'value' => $open > 0 ? $open.' failed' : ($rate === null ? 'Idle' : $rate.'%'),
            'tone' => $open > 0 ? 'danger' : ($rate === null ? 'neutral' : ($rate < self::HEALTHY_RATE ? 'warning' : 'success')),
            'icon' => 'Server',
            'href' => route('admin.services.module-log.index'),
        ];
    }
}
