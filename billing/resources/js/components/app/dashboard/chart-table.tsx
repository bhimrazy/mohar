import type {
    ChartSeries,
    ChartXFormat,
} from '@/components/app/time-series-chart';
import { formatXLong, inferXFormat } from '@/components/app/time-series-chart';
import { formatMoney } from '@/lib/money';

/** The table twin of a time-series chart: one row per x, one column per series (plus a total for stacks). */
export function ChartTable({
    series,
    currency,
    xFormat,
    caption,
    total = false,
    className,
}: {
    series: ChartSeries[];
    currency?: string;
    xFormat?: ChartXFormat;
    caption: string;
    total?: boolean;
    className?: string;
}) {
    const points = series[0]?.points ?? [];
    const format = xFormat ?? inferXFormat(points);
    const value = (y: number) =>
        currency ? formatMoney(y, currency) : y.toLocaleString();

    return (
        <div className={className}>
            <div className="max-h-72 overflow-auto rounded-md border">
                <table className="w-full text-sm">
                    <caption className="sr-only">{caption}</caption>
                    <thead className="sticky top-0 bg-muted text-xs text-muted-foreground">
                        <tr>
                            <th
                                scope="col"
                                className="px-3 py-2 text-left font-medium"
                            >
                                {format === 'month' ? 'Month' : 'Day'}
                            </th>
                            {series.map((item) => (
                                <th
                                    key={item.name}
                                    scope="col"
                                    className="px-3 py-2 text-right font-medium"
                                >
                                    {item.name}
                                </th>
                            ))}
                            {total && (
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right font-medium"
                                >
                                    Total
                                </th>
                            )}
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {points.map((point, row) => (
                            <tr key={point.x}>
                                <th
                                    scope="row"
                                    className="px-3 py-1.5 text-left font-normal text-muted-foreground"
                                >
                                    {formatXLong(point.x, format)}
                                </th>
                                {series.map((item) => (
                                    <td
                                        key={item.name}
                                        className="px-3 py-1.5 text-right tabular-nums"
                                    >
                                        {value(item.points[row]?.y ?? 0)}
                                    </td>
                                ))}
                                {total && (
                                    <td className="px-3 py-1.5 text-right font-medium tabular-nums">
                                        {value(
                                            series.reduce(
                                                (sum, item) =>
                                                    sum +
                                                    (item.points[row]?.y ?? 0),
                                                0,
                                            ),
                                        )}
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
