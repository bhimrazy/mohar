import { useId } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    XAxis,
    YAxis,
} from 'recharts';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { formatMoney, formatMoneyCompact } from '@/lib/money';
import { cn } from '@/lib/utils';

/** Matches the backend `chart` dashboard widget payload (App\Support\Dashboard\Dashboard). */
export type ChartSeries = {
    name: string;
    points: { x: string; y: number }[];
};

type TimeSeriesChartProps = {
    series: ChartSeries[];
    kind?: 'line' | 'bar';
    /** When set, `y` values are minor units and are formatted as money. */
    currency?: string;
    locale?: string;
    /** Formats x values (ISO dates) for the axis. Defaults to "Oct". */
    formatX?: (x: string) => string;
    /** Stack bars / areas instead of overlaying them. */
    stacked?: boolean;
    /** "zero" starts the y axis at 0 (totals); "fit" zooms to the data range (rates, prices). */
    yScale?: 'zero' | 'fit';
    showLegend?: boolean;
    className?: string;
    /** Accessible name, e.g. "Income, last 12 months". */
    label: string;
};

const SERIES_COLORS = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
    'var(--chart-6)',
];

function parseDay(x: string): Date {
    return new Date(`${x.slice(0, 10)}T00:00:00`);
}

/** Monthly series → "Oct"; daily/weekly series → "Oct 7". */
function makeFormatX(points: { x: string }[]): (x: string) => string {
    const first = parseDay(points[0]?.x ?? '');
    const second = parseDay(points[1]?.x ?? '');
    const gapDays = (second.getTime() - first.getTime()) / 86_400_000;
    const options: Intl.DateTimeFormatOptions =
        Number.isFinite(gapDays) && gapDays < 27
            ? { month: 'short', day: 'numeric' }
            : { month: 'short' };

    return (x) => {
        const date = parseDay(x);

        return Number.isNaN(date.getTime())
            ? x
            : date.toLocaleDateString('en-US', options);
    };
}

/** Themed area/bar chart for dashboard widgets, built on shadcn Chart (Recharts). */
export function TimeSeriesChart({
    series,
    kind = 'line',
    currency,
    locale,
    formatX: customFormatX,
    stacked = false,
    yScale = 'zero',
    showLegend = series.length > 1,
    className,
    label,
}: TimeSeriesChartProps) {
    const uid = useId().replace(/:/g, '');
    const formatX = customFormatX ?? makeFormatX(series[0]?.points ?? []);
    const keys = series.map((_, index) => `s${index}`);
    const config: ChartConfig = Object.fromEntries(
        series.map((item, index) => [
            keys[index],
            { label: item.name, color: SERIES_COLORS[index % 6] },
        ]),
    );
    const data = (series[0]?.points ?? []).map((point, row) => ({
        x: point.x,
        ...Object.fromEntries(
            series.map((item, index) => [
                keys[index],
                item.points[row]?.y ?? 0,
            ]),
        ),
    }));

    const formatValue = (value: number) =>
        currency
            ? formatMoney(value, currency, locale)
            : value.toLocaleString(locale, { maximumFractionDigits: 6 });
    const formatAxis = (value: number) =>
        currency
            ? formatMoneyCompact(value, currency, locale)
            : Intl.NumberFormat(
                  locale,
                  yScale === 'fit'
                      ? { maximumFractionDigits: 2 }
                      : { notation: 'compact' },
              ).format(value);

    const common = (
        <>
            <CartesianGrid
                vertical={false}
                stroke="var(--chart-grid)"
                strokeDasharray="0"
            />
            <XAxis
                dataKey="x"
                tickLine={false}
                axisLine={false}
                tickMargin={10}
                minTickGap={16}
                tickFormatter={formatX}
            />
            <YAxis
                tickLine={false}
                axisLine={false}
                tickMargin={8}
                width={56}
                tickCount={4}
                allowDecimals={Boolean(currency) || yScale === 'fit'}
                domain={yScale === 'fit' ? ['auto', 'auto'] : undefined}
                tickFormatter={formatAxis}
            />
            <ChartTooltip
                cursor={kind === 'bar' ? { fill: 'var(--muted)' } : true}
                content={
                    <ChartTooltipContent
                        indicator={kind === 'bar' ? 'dot' : 'line'}
                        labelFormatter={(_, payload) => {
                            const x = payload?.[0]?.payload?.x as
                                | string
                                | undefined;

                            return x ? formatX(x) : '';
                        }}
                        formatter={(value, name) => (
                            <div className="flex w-full items-center justify-between gap-4">
                                <span className="text-muted-foreground">
                                    {config[name as string]?.label}
                                </span>
                                <span className="font-medium text-foreground tabular-nums">
                                    {formatValue(Number(value))}
                                </span>
                            </div>
                        )}
                    />
                }
            />
            {showLegend && <ChartLegend content={<ChartLegendContent />} />}
        </>
    );

    return (
        <ChartContainer
            config={config}
            className={cn('aspect-auto h-64 w-full', className)}
            role="img"
            aria-label={label}
        >
            {kind === 'bar' ? (
                <BarChart data={data} margin={{ left: 0, right: 4, top: 8 }}>
                    {common}
                    {keys.map((key, index) => (
                        <Bar
                            key={key}
                            dataKey={key}
                            fill={`var(--color-${key})`}
                            stackId={stacked ? 'stack' : undefined}
                            radius={
                                stacked && index < keys.length - 1
                                    ? 0
                                    : [4, 4, 0, 0]
                            }
                            maxBarSize={28}
                            isAnimationActive={false}
                        />
                    ))}
                </BarChart>
            ) : (
                <AreaChart data={data} margin={{ left: 0, right: 4, top: 8 }}>
                    <defs>
                        {keys.map((key) => (
                            <linearGradient
                                key={key}
                                id={`${uid}-${key}`}
                                x1="0"
                                y1="0"
                                x2="0"
                                y2="1"
                            >
                                <stop
                                    offset="0%"
                                    stopColor={`var(--color-${key})`}
                                    stopOpacity={0.24}
                                />
                                <stop
                                    offset="95%"
                                    stopColor={`var(--color-${key})`}
                                    stopOpacity={0.01}
                                />
                            </linearGradient>
                        ))}
                    </defs>
                    {common}
                    {keys.map((key, index) => (
                        <Area
                            key={key}
                            dataKey={key}
                            type="monotone"
                            stroke={`var(--color-${key})`}
                            strokeWidth={index === 0 ? 2 : 1.5}
                            strokeDasharray={index === 0 ? undefined : '4 4'}
                            fill={index === 0 ? `url(#${uid}-${key})` : 'none'}
                            stackId={stacked ? 'stack' : undefined}
                            isAnimationActive={false}
                            activeDot={{
                                r: 4,
                                strokeWidth: 2,
                                stroke: 'var(--card)',
                            }}
                        />
                    ))}
                </AreaChart>
            )}
        </ChartContainer>
    );
}
