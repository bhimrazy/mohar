import type { StatTrend } from '@/components/app/stat-card';
import type { StatusTone } from '@/components/app/status-badge';
import type {
    ChartSeries,
    ChartXFormat,
} from '@/components/app/time-series-chart';

/**
 * Shapes produced by App\Support\Dashboard\Dashboard::resolveWidgets() / resolveHealth(). Every field beyond
 * the original v1 contract is optional, so modules that do not send it keep working.
 */
export type MoneyValue = { amount: number; currency: string };

/** Per-currency overrides merged over the base payload by the reporting-currency switch. */
export type Variants<T> = Record<string, Partial<T>>;

type WidgetBase = { key: string; size: 'sm' | 'md' | 'lg'; order?: number };

export type StatWidget = WidgetBase & {
    type: 'stat';
    label: string;
    /** Display value, or money in minor units (legacy object form). */
    value: string | number | MoneyValue;
    /** Raw money; preferred over `value` for formatting. */
    money?: MoneyValue;
    href?: string;
    icon?: string;
    hint?: string;
    trend?: StatTrend;
    sparkline?: number[];
    tone?: 'default' | 'danger' | 'warning';
    /** A KPI for the top row (at most four are shown there). */
    primary?: boolean;
    /** Same figure as the Needs attention item with this key: shown there only, not in the counter strip. */
    mirrorsTodo?: boolean;
    variants?: Variants<StatWidget>;
};

export type DashboardListItem = {
    title: string;
    subtitle?: string;
    href?: string | null;
    /** Right-aligned text: an amount, an ISO date-time (shown relative) or a date. */
    meta?: string | null;
    money?: MoneyValue;
    status?: string | null;
    /** A name to show as initials in front of the row. */
    avatar?: string;
    /** ISO date shown after the subtitle, prefixed with `dateLabel`. */
    date?: string | null;
    dateLabel?: string;
    /** A button at the end of the row ("Pay"). */
    action?: { label: string; href: string } | null;
};

export type ListWidgetData = WidgetBase & {
    type: 'list';
    title: string;
    items: DashboardListItem[];
    href?: string;
    empty?: string;
    /** The next step offered in the empty state ("Register a domain"). */
    emptyAction?: { label: string; href: string } | null;
    /** Lists sharing a group render as tabs of one card titled `groupTitle`. */
    group?: string;
    groupTitle?: string;
    /** Shorter title for the tab. */
    tabTitle?: string;
};

/** A banner, e.g. the client's invoice to pay. Rendered by the client dashboard only. */
export type NoticeWidgetData = WidgetBase & {
    type: 'notice';
    title: string;
    body?: string;
    tone: 'warning' | 'danger' | 'info';
    actions: { label: string; href: string; primary?: boolean }[];
};

export type ChartSummary = {
    money?: MoneyValue;
    value?: number;
    label?: string;
    trend?: (StatTrend & { label?: string }) | null;
};

export type ChartRange = {
    label: string;
    series: ChartSeries[];
    summary?: ChartSummary;
    xFormat?: ChartXFormat;
};

export type ChartWidget = WidgetBase & {
    type: 'chart';
    title: string;
    description?: string;
    kind?: 'line' | 'bar';
    currency?: string;
    stacked?: boolean;
    xFormat?: ChartXFormat;
    series: ChartSeries[];
    summary?: ChartSummary;
    ranges?: Record<string, ChartRange>;
    /** Legacy per-currency series (v1); converted to variants on the client. */
    currencies?: Record<string, ChartSeries[]>;
    variants?: Variants<ChartWidget>;
    href?: string;
    empty?: string;
};

export type BreakdownRow = {
    key: string;
    label: string;
    code?: string;
    money?: MoneyValue;
    value?: number;
    /** Preformatted value (e.g. "320 ms"); wins over money and value. */
    display?: string;
    /** Share of the whole, 0–100. */
    share: number;
};

export type BreakdownWidget = WidgetBase & {
    type: 'breakdown';
    title: string;
    description?: string;
    rows: BreakdownRow[];
    notesTitle?: string;
    notes?: { label: string; money: MoneyValue }[];
    href?: string;
    empty?: string;
};

export type DashboardWidget =
    | StatWidget
    | ListWidgetData
    | ChartWidget
    | BreakdownWidget
    | NoticeWidgetData;

export type HealthCheck = {
    key: string;
    label: string;
    detail?: string;
    value: string;
    tone: StatusTone;
    icon?: string;
    href?: string;
};
