<?php

namespace App\Modules\Services\Support;

use App\Enums\BillingCycle;
use App\Models\User;
use App\Modules\Core\Support\ConstantRows;
use App\Modules\Core\Support\CurrencyRollup;
use App\Modules\Services\Enums\QueueStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Support\Dashboard\Dashboard;
use App\Support\Dashboard\Trend;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Services' admin dashboard KPI (monthly recurring revenue) and the provisioning health check.
 */
class ServicesDashboard
{
    /** Month-end points on the MRR sparkline. */
    private const MONTHS = 12;

    /** Seconds the MRR figures are cached (as BillingDashboard::CACHE_SECONDS). */
    public const CACHE_SECONDS = 60;

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
        $rollup = app(CurrencyRollup::class);
        $today = CarbonImmutable::today();
        $points = [];

        for ($month = self::MONTHS - 1; $month >= 1; $month--) {
            $points[] = $today->subMonths($month)->endOfMonth()->toDateString();
        }

        $points[] = $today->toDateString();
        $monthAgo = $today->subMonth()->toDateString();
        ['series' => $series, 'previous' => $previous, 'count' => $count] = Cache::remember(
            'dashboard.services.mrr.'.$today->toDateString(),
            self::CACHE_SECONDS,
            fn (): array => $this->figures($today, $points, $monthAgo),
        );

        $card = fn (array $points, int $before, int $services, string $currency): array => [
            'value' => $rollup->format((int) end($points), $currency),
            'money' => ['amount' => (int) end($points), 'currency' => $currency],
            'sparkline' => $points,
            'hint' => trans_choice('{0} No recurring services|{1} From 1 recurring service|[2,*] From :count recurring services', $services),
            ...array_filter(['trend' => Trend::percent((int) end($points), $before)]),
        ];
        $codes = $rollup->codes(array_keys($series));
        $variants = [];

        foreach ($codes as $code) {
            $variants[$code] = $card($series[$code] ?? array_fill(0, count($points), 0), $previous[$code] ?? 0, $count[$code] ?? 0, $code);
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
            'primary' => true,
            'variants' => $variants === [] ? (object) [] : $variants,
        ];
    }

    /**
     * Per-currency MRR at each point, a month ago, and the number of open services, summed in SQL (one row per
     * currency) and cached briefly like Billing's dashboard dataset.
     *
     * @param  list<string>  $points  month ends, oldest first, then today
     * @return array{series: array<string, list<int>>, previous: array<string, int>, count: array<string, int>}
     */
    private function figures(CarbonImmutable $today, array $points, string $monthAgo): array
    {
        $open = [ServiceStatus::Active->value, ServiceStatus::Suspended->value];
        $cycles = ConstantRows::of('? as cycle, ? + 0 as months', array_map(fn (BillingCycle $cycle): array => [$cycle->value, (int) $cycle->months()], BillingCycle::recurring()));

        // Per service: open today or not, its amount per month (rounded per service), and its first and ended day.
        $services = DB::table('services')
            ->joinSub($cycles, 'cycles', 'cycles.cycle', '=', 'services.cycle')
            ->whereNull('services.deleted_at')
            ->whereIn('services.status', [...$open, ServiceStatus::Terminated->value, ServiceStatus::Cancelled->value])
            ->where('services.recurring_amount', '>', 0)
            ->where(fn ($query) => $query->whereIn('services.status', $open)->orWhere('services.updated_at', '>=', $today->subMonths(self::MONTHS)->startOfMonth()))
            ->selectRaw('services.currency, case when services.status in (?, ?) then 1 else 0 end as is_open, round(services.recurring_amount * 1.0 / cycles.months) as monthly, '
                .'date(services.registration_date) as registered, date(coalesce(services.termination_date, services.updated_at)) as ended', $open);

        // Points 0–10: month ends; 11: today, which counts open services only; 12: a month ago. Active on a day:
        // registered by then, and still open or ended after it.
        $days = [...$points, $monthAgo];
        $select = ['currency', 'sum(is_open) as services'];
        $bindings = [];

        foreach (range(0, self::MONTHS) as $point) {
            if ($point === self::MONTHS - 1) {
                $select[] = "sum(case when is_open = 1 then monthly else 0 end) as point_{$point}";

                continue;
            }

            $select[] = "sum(case when (is_open = 1 or ended > ?) and registered <= ? then monthly else 0 end) as point_{$point}";
            array_push($bindings, $days[$point], $days[$point]);
        }

        $series = [];
        $previous = [];
        $count = [];

        foreach (DB::query()->fromSub($services, 'mrr')->selectRaw(implode(', ', $select), $bindings)->groupBy('currency')->orderBy('currency')->get() as $row) {
            $sums = (array) $row;
            $code = (string) $row->currency;
            $series[$code] = array_map(fn (int $point): int => (int) $sums["point_{$point}"], range(0, self::MONTHS - 1));
            $previous[$code] = (int) $sums['point_'.self::MONTHS];
            $count[$code] = (int) $row->services;
        }

        return ['series' => $series, 'previous' => $previous, 'count' => $count];
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
