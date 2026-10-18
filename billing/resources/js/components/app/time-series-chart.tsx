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
    /** A comparison baseline (previous period): drawn as a neutral dashed line, never a second hue. */
    compare?: boolean;
    /** Fixed chart colour slot 1–6, so a category keeps its colour when others are absent. */
    slot?: number;
    /** For series that *are* a status (succeeded / failed / not run): the reserved status colour. */
    tone?: 'success' | 'danger' | 'warning' | 'neutral';
};

/** Named x-axis formats sent by the backend. */
export type ChartXFormat = 'month' | 'day' | 'dayOfMonth' | 'week';

type TimeSeriesChartProps = {
    series: ChartSeries[];
    kind?: 'line' | 'bar';
    /** When set, `y` values are minor units and are formatted as money. */
    currency?: string;
    locale?: string;
    /** Formats x values (ISO dates) for the axis; a named format or a function. Defaults by point spacing. */
    formatX?: ChartXFormat | ((x: string) => string);
    /** Stack bars / areas instead of overlaying them. */
    stacked?: boolean;
    showLegend?: boolean;
    className?: string;
    /** Accessible name, e.g. "Income, last 12 months". */
    label: string;
};

const COMPARE_COLOR = 'var(--muted-foreground)';

export function seriesColor(series: ChartSeries, index: number): string {
    if (series.compare) {
        return COMPARE_COLOR;
    }

    if (series.tone) {
        return series.tone === 'neutral'
            ? 'var(--muted-foreground)'
            : `var(--${series.tone})`;
    }

    return `var(--chart-${(((series.slot ?? index + 1) - 1) % 6) + 1})`;
}

function parseDay(x: string): Date {
    return new Date(`${x.slice(0, 10)}T00:00:00`);
}

const NAMED_FORMATS: Record<ChartXFormat, Intl.DateTimeFormatOptions> = {
    month: { month: 'short' },
    day: { month: 'short', day: 'numeric' },
    dayOfMonth: { day: 'numeric' },
    week: { month: 'short', day: 'numeric' },
};

/** Long form for tooltips and tables: "October 2026", "Oct 7, 2026". */
export function formatXLong(x: string, format: ChartXFormat): string {
    const date = parseDay(x);

    if (Number.isNaN(date.getTime())) {
        return x;
    }

    return format === 'month'
        ? date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })
        : date.toLocaleDateString('en-US', {
              weekday: format === 'week' ? undefined : 'short',
              month: 'short',
              day: 'numeric',
          });
}

/** Infers the format from point spacing: monthly → "Oct", otherwise "Oct 7". */
export function inferXFormat(points: { x: string }[]): ChartXFormat {
    const first = parseDay(points[0]?.x ?? '');
    const second = parseDay(points[1]?.x ?? '');
    const gapDays = (second.getTime() - first.getTime()) / 86_400_000;

    return Number.isFinite(gapDays) && gapDays >= 27 ? 'month' : 'day';
}

function makeFormatX(
    format: ChartXFormat | ((x: string) => string) | undefined,
    points: { x: string }[],
): (x: string) => string {
    if (typeof format === 'function') {
        return format;
    }

    const options = NAMED_FORMATS[format ?? inferXFormat(points)];

    return (x) => {
        const date = parseDay(x);

        return Number.isNaN(date.getTime())
            ? x
            : date.toLocaleDateString('en-US', options);
    };
}

type BarShapeProps = {
    x?: number;
    y?: number;
    width?: number;
    height?: number;
    fill?: string;
    payload?: Record<string, number>;
};

/**
 * A stacked segment: 4px rounded data-end only on the top-most non-zero segment, square at the baseline, and a
 * 2px surface-coloured gap between segments.
 */
function stackShape(keys: string[], key: string) {
    return function StackSegment({
        x = 0,
        y = 0,
        width = 0,
        height = 0,
        fill,
        payload,
    }: BarShapeProps) {
        if (height <= 0) {
            return null;
        }

        const top = [...keys].reverse().find((k) => (payload?.[k] ?? 0) > 0);
        const gap = key === keys[0] ? 0 : 2;
        const h = Math.max(height - gap, 1);
        const r = key === top ? Math.min(4, width / 2, h) : 0;

        return (
            <path
                fill={fill}
                d={`M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + width - r}Q${x + width},${y} ${x + width},${y + r}V${y + h}Z`}
            />
        );
    };
}

/** Themed area/bar chart for dashboard widgets, built on shadcn Chart (Recharts). */
export function TimeSeriesChart({
    series,
    kind = 'line',
    currency,
    locale,
    formatX: xFormat,
    stacked = false,
    showLegend = series.length > 1,
    className,
    label,
}: TimeSeriesChartProps) {
    const uid = useId().replace(/:/g, '');
    const formatX = makeFormatX(xFormat, series[0]?.points ?? []);
    const keys = series.map((_, index) => `s${index}`);
    const config: ChartConfig = Object.fromEntries(
        series.map((item, index) => [
            keys[index],
            { label: item.name, color: seriesColor(item, index) },
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
            : value.toLocaleString(locale);
    const formatAxis = (value: number) =>
        currency
            ? formatMoneyCompact(value, currency, locale)
            : Intl.NumberFormat(locale, { notation: 'compact' }).format(value);

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
                minTickGap={kind === 'bar' ? 4 : 16}
                tickFormatter={formatX}
                className="tabular-nums"
            />
            <YAxis
                tickLine={false}
                axisLine={false}
                tickMargin={8}
                width={currency ? 64 : 32}
                tickCount={4}
                allowDecimals={false}
                tickFormatter={formatAxis}
                className="tabular-nums"
            />
            <ChartTooltip
                cursor={
                    kind === 'bar'
                        ? { fill: 'var(--muted)', opacity: 0.6 }
                        : { stroke: 'var(--border)', strokeWidth: 1 }
                }
                content={
                    <ChartTooltipContent
                        indicator={kind === 'bar' ? 'dot' : 'line'}
                        labelFormatter={(_, payload) => {
                            const x = payload?.[0]?.payload?.x as
                                | string
                                | undefined;

                            return x ? formatX(x) : '';
                        }}
                        formatter={(value, name, item) => (
                            <div className="flex w-full items-center justify-between gap-4">
                                <span className="flex items-center gap-1.5 text-muted-foreground">
                                    <span
                                        aria-hidden="true"
                                        className="h-0.5 w-3 rounded-full"
                                        style={{
                                            background:
                                                item.color ??
                                                config[name as string]?.color,
                                        }}
                                    />
                                    {config[name as string]?.label}
                                </span>
                                <span className="font-medium text-foreground tabular-nums">
                                    {formatValue(Number(value))}
                                </span>
                            </div>
                        )}
                        hideIndicator
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
                <BarChart
                    data={data}
                    margin={{ left: 0, right: 4, top: 8 }}
                    barCategoryGap="22%"
                >
                    {common}
                    {keys.map((key) => (
                        <Bar
                            key={key}
                            dataKey={key}
                            fill={`var(--color-${key})`}
                            stackId={stacked ? 'stack' : undefined}
                            radius={stacked ? undefined : [4, 4, 0, 0]}
                            shape={stacked ? stackShape(keys, key) : undefined}
                            maxBarSize={24}
                            isAnimationActive={false}
                        />
                    ))}
                </BarChart>
            ) : (
                <AreaChart data={data} margin={{ left: 0, right: 8, top: 8 }}>
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
                                    stopOpacity={0.16}
                                />
                                <stop
                                    offset="100%"
                                    stopColor={`var(--color-${key})`}
                                    stopOpacity={0.02}
                                />
                            </linearGradient>
                        ))}
                    </defs>
                    {common}
                    {/* Draw comparison baselines first so the current period sits on top. */}
                    {keys
                        .map((key, index) => ({ key, index }))
                        .sort(
                            (a, b) =>
                                Number(!series[a.index].compare) -
                                Number(!series[b.index].compare),
                        )
                        .map(({ key, index }) => {
                            const isCompare = series[index].compare === true;
                            const isFirst =
                                series.findIndex((item) => !item.compare) ===
                                index;

                            return (
                                <Area
                                    key={key}
                                    dataKey={key}
                                    type="monotone"
                                    stroke={`var(--color-${key})`}
                                    strokeWidth={isCompare ? 1.5 : 2}
                                    strokeDasharray={
                                        isCompare ? '4 4' : undefined
                                    }
                                    strokeOpacity={isCompare ? 0.7 : 1}
                                    fill={
                                        isFirst && !stacked
                                            ? `url(#${uid}-${key})`
                                            : stacked
                                              ? `var(--color-${key})`
                                              : 'none'
                                    }
                                    fillOpacity={stacked ? 0.12 : 1}
                                    stackId={stacked ? 'stack' : undefined}
                                    isAnimationActive={false}
                                    activeDot={{
                                        r: isCompare ? 3 : 4,
                                        strokeWidth: 2,
                                        stroke: 'var(--card)',
                                    }}
                                />
                            );
                        })}
                </AreaChart>
            )}
        </ChartContainer>
    );
}
