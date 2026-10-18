import { ArrowDownRight, ArrowRight, ArrowUpRight } from 'lucide-react';
import { useId, useState } from 'react';
import type { ChartSeries } from '@/components/app/time-series-chart';
import {
    seriesColor,
    TimeSeriesChart,
} from '@/components/app/time-series-chart';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import { ChartTable } from './chart-table';
import { Panel, TableToggle, ViewAllLink } from './panel';
import {
    ALL_CURRENCIES,
    useReportingCurrency,
    useVariant,
} from './reporting-currency';
import type { ChartSummary, ChartWidget } from './types';

function isEmpty(series: ChartSeries[]): boolean {
    return series.every((item) => item.points.every((point) => point.y === 0));
}

/** Legend with line keys that mirror the marks (solid for the period, dashed for the baseline). */
function LineLegend({ series }: { series: ChartSeries[] }) {
    return (
        <ul className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
            {series.map((item, index) => (
                <li
                    key={item.name}
                    className="inline-flex items-center gap-1.5"
                >
                    <svg
                        width="16"
                        height="6"
                        aria-hidden="true"
                        className="shrink-0"
                    >
                        <line
                            x1="1"
                            x2="15"
                            y1="3"
                            y2="3"
                            stroke={seriesColor(item, index)}
                            strokeWidth={item.compare ? 1.5 : 2}
                            strokeDasharray={item.compare ? '3 3' : undefined}
                            strokeLinecap="round"
                        />
                    </svg>
                    {item.name}
                </li>
            ))}
        </ul>
    );
}

function SummaryTrend({
    trend,
}: {
    trend: NonNullable<ChartSummary['trend']>;
}) {
    const good =
        trend.direction === 'flat'
            ? null
            : (trend.direction === 'up') === (trend.upIsGood ?? true);
    const Icon =
        trend.direction === 'up'
            ? ArrowUpRight
            : trend.direction === 'down'
              ? ArrowDownRight
              : ArrowRight;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 rounded-badge px-1.5 py-0.5 text-xs font-medium tabular-nums',
                good === true && 'bg-success-subtle text-success-text',
                good === false && 'bg-danger-subtle text-danger-text',
                good === null && 'bg-muted text-muted-foreground',
            )}
        >
            <Icon className="size-3.5" aria-hidden="true" />
            <span className="sr-only">
                {trend.direction === 'up'
                    ? 'Up'
                    : trend.direction === 'down'
                      ? 'Down'
                      : 'No change'}
            </span>
            {trend.value}
            {trend.label && <span className="font-normal"> {trend.label}</span>}
        </span>
    );
}

function Headline({
    summary,
    currency,
}: {
    summary: ChartSummary;
    currency?: string;
}) {
    const amount = summary.money
        ? formatMoney(summary.money.amount, summary.money.currency)
        : summary.value !== undefined
          ? summary.value.toLocaleString()
          : null;

    if (amount === null) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
            <span className="font-display font-numeric text-2xl font-semibold text-foreground">
                {amount}
            </span>
            {summary.label && (
                <span className="text-sm text-muted-foreground">
                    {summary.label}
                </span>
            )}
            {summary.trend && <SummaryTrend trend={summary.trend} />}
            {!summary.trend && currency && (
                <span className="text-xs text-muted-foreground">
                    No earlier data to compare
                </span>
            )}
        </div>
    );
}

/**
 * A dashboard chart card. `prominent` is the income hero: headline total with its change, a range switch,
 * a line legend and the reporting-currency caption.
 */
export function ChartCard({
    widget: base,
    prominent = false,
    className,
}: {
    widget: ChartWidget;
    prominent?: boolean;
    className?: string;
}) {
    const widget = useVariant(base);
    const reporting = useReportingCurrency();
    const titleId = useId();
    const rangeKeys = Object.keys(widget.ranges ?? {});
    const [rangeKey, setRangeKey] = useState(rangeKeys[0] ?? '');
    const [asTable, setAsTable] = useState(false);
    const range = widget.ranges?.[rangeKey];
    const series = range?.series ?? widget.series;
    const summary = range?.summary ?? widget.summary;
    const xFormat = range?.xFormat ?? widget.xFormat;
    const kind = widget.kind ?? 'line';
    const hasCompare = series.some((item) => item.compare);
    const label = `${widget.title}${range ? `, ${range.label.toLowerCase()}` : ''}${widget.currency ? ` in ${widget.currency}` : ''}`;
    const consolidated =
        reporting === ALL_CURRENCIES &&
        Object.keys(base.variants ?? {}).length > 1;

    const description = prominent
        ? summary && <Headline summary={summary} currency={widget.currency} />
        : widget.description;

    return (
        <Panel
            titleId={titleId}
            className={className}
            title={widget.title}
            description={description}
            actions={
                <>
                    {rangeKeys.length > 1 && (
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            value={rangeKey}
                            onValueChange={(value) =>
                                value && setRangeKey(value)
                            }
                            aria-label={`${widget.title} range`}
                        >
                            {rangeKeys.map((key) => (
                                <ToggleGroupItem
                                    key={key}
                                    value={key}
                                    className="px-2.5 text-xs"
                                >
                                    {widget.ranges?.[key]?.label}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    )}
                    <TableToggle
                        pressed={asTable}
                        onPressedChange={setAsTable}
                        label={widget.title}
                    />
                    {widget.href && !prominent && (
                        <ViewAllLink href={widget.href} />
                    )}
                </>
            }
        >
            {prominent && (
                <div className="-mt-1 mb-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                    {hasCompare ? <LineLegend series={series} /> : <span />}
                    {widget.currency && (
                        <span className="text-xs text-muted-foreground">
                            {consolidated
                                ? `All currencies in ${widget.currency} at today's rates`
                                : `Reporting in ${widget.currency}`}
                        </span>
                    )}
                </div>
            )}
            {isEmpty(series) ? (
                <p className="flex flex-1 items-center justify-center py-10 text-center text-sm text-muted-foreground">
                    {widget.empty ?? 'No data for this period yet.'}
                </p>
            ) : asTable ? (
                <ChartTable
                    series={series}
                    currency={widget.currency}
                    xFormat={xFormat}
                    caption={label}
                    total={widget.stacked}
                />
            ) : (
                <TimeSeriesChart
                    label={label}
                    kind={kind}
                    stacked={widget.stacked}
                    currency={widget.currency}
                    series={series}
                    formatX={xFormat}
                    showLegend={!prominent && series.length > 1}
                    className={prominent ? 'h-auto min-h-72 flex-1' : 'h-56'}
                />
            )}
        </Panel>
    );
}
