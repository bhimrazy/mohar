import { useId } from 'react';
import { Area, AreaChart, YAxis } from 'recharts';
import { ChartContainer } from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { cn } from '@/lib/utils';

type SparklineProps = {
    values: number[];
    /** Any CSS colour; defaults to the theme's first chart colour. */
    color?: string;
    className?: string;
    /** Accessible summary, e.g. "Income over the last 12 weeks, rising". */
    label?: string;
};

/** Tiny trend line for stat cards (shadcn Chart / Recharts). Decorative unless `label` is set. */
export function Sparkline({
    values,
    color = 'var(--chart-1)',
    className,
    label,
}: SparklineProps) {
    const gradientId = `spark-${useId().replace(/:/g, '')}`;
    const data = values.map((value, index) => ({ index, value }));
    const config = { value: { label: 'Value', color } } satisfies ChartConfig;

    return (
        <ChartContainer
            config={config}
            className={cn('aspect-auto h-10 w-full', className)}
            role={label ? 'img' : undefined}
            aria-label={label}
            aria-hidden={label ? undefined : true}
        >
            <AreaChart
                accessibilityLayer={false}
                data={data}
                margin={{ top: 2, right: 0, bottom: 0, left: 0 }}
            >
                <defs>
                    <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                        <stop
                            offset="0%"
                            stopColor="var(--color-value)"
                            stopOpacity={0.28}
                        />
                        <stop
                            offset="100%"
                            stopColor="var(--color-value)"
                            stopOpacity={0}
                        />
                    </linearGradient>
                </defs>
                <YAxis hide domain={['dataMin', 'dataMax']} />
                <Area
                    dataKey="value"
                    type="monotone"
                    stroke="var(--color-value)"
                    strokeWidth={1.75}
                    fill={`url(#${gradientId})`}
                    isAnimationActive={false}
                    dot={false}
                />
            </AreaChart>
        </ChartContainer>
    );
}
