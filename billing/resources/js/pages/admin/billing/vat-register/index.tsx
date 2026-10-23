import { Link, router } from '@inertiajs/react';
import { AlertTriangle, BookOpen, Download } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { DataTable } from '@/components/app/data-table';
import { humanize } from '@/components/billing/types';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { Head } from '@/components/app/head';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { SectionCard } from '@/components/app/section-card';
import { StatCard } from '@/components/app/stat-card';
import { StepUpDownloadLink } from '@/components/app/step-up-download-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import {
    exportMethod,
    index,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/VatRegisterController';
import type { LaravelPaginated, MoneyValue, SelectOption } from '@/types/app';

type Tab = 'register' | 'notes';
type Kind = 'month' | 'year' | 'range';
type Cell = string | number | MoneyValue | null;
type Row = Record<string, Cell>;
type Column = {
    key: string;
    label: string;
    type: 'text' | 'money' | 'integer' | 'decimal' | 'date' | 'instant';
};

type Props = {
    tab: Tab;
    tabs: Tab[];
    columns: Column[];
    rows: LaravelPaginated<Row>;
    totals: Row[];
    header: [string, string][];
    period: {
        kind: Kind;
        label: string;
        from: string;
        to: string;
        months: number;
    };
    filters: Partial<Record<'month' | 'year' | 'from' | 'to', string>>;
    filterErrors: Record<string, string>;
    options: {
        months: SelectOption[];
        years: SelectOption[];
        lastClosed: string;
    };
    warnings: { withoutPan: number; unconverted: number };
    can: { export: boolean };
};

const TAB_LABELS: Record<Tab, string> = {
    register: 'Sales register',
    notes: 'Credit and debit notes',
};

const BS_MONTHS = [
    'Baisakh',
    'Jestha',
    'Asar',
    'Shrawan',
    'Bhadra',
    'Asoj',
    'Kartik',
    'Mangsir',
    'Poush',
    'Magh',
    'Falgun',
    'Chaitra',
];

/** Columns shown together in the date cell, or left for the export because they are blank for services. */
const MERGED = ['ad_date', 'invoice_bs_date', 'export_declaration'];

/** "2083-07-03" → "3 Kartik 2083". */
function bsDate(value: Cell): string {
    const match =
        typeof value === 'string'
            ? /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
            : null;

    return match
        ? `${Number(match[3])} ${BS_MONTHS[Number(match[2]) - 1]} ${match[1]}`
        : '—';
}

/** A text or number cell as a string; '' for money or empty. */
function text(cell: Cell): string {
    return typeof cell === 'string' || typeof cell === 'number'
        ? String(cell)
        : '';
}

function isMoney(cell: Cell): cell is MoneyValue {
    return typeof cell === 'object' && cell !== null && 'amount' in cell;
}

function MoneyCell({ cell }: { cell: Cell }) {
    return isMoney(cell) ? (
        <Money amount={cell.amount} currency={cell.currency} tone="ledger" />
    ) : (
        <span className="text-muted-foreground">—</span>
    );
}

function TextCell({ cell }: { cell: Cell }) {
    return cell === null || cell === '' ? (
        <span className="text-muted-foreground">—</span>
    ) : (
        <>{text(cell)}</>
    );
}

export default function VatRegister({
    tab,
    tabs,
    columns,
    rows,
    totals,
    header,
    period,
    filters,
    filterErrors,
    options,
    warnings,
    can,
}: Props) {
    const [kind, setKind] = useState<Kind>(period.kind);
    const [month, setMonth] = useState(filters.month ?? options.lastClosed);
    const [year, setYear] = useState(
        filters.year ?? options.years[0]?.value ?? '',
    );
    const [from, setFrom] = useState(filters.from ?? period.from);
    const [to, setTo] = useState(filters.to ?? period.to);

    const apply = (event: FormEvent) => {
        event.preventDefault();
        const query =
            kind === 'month'
                ? { month }
                : kind === 'year'
                  ? { year }
                  : { from, to };
        router.get(
            index.url({ query: { tab, ...query } }),
            {},
            { preserveScroll: true },
        );
    };

    const exportUrl = exportMethod.url({ query: { tab, ...filters } });
    const net = totals.at(-1);
    const monthRows = totals.slice(0, period.months);
    const kindRows = totals.slice(period.months);
    const labelKey = tab === 'register' ? 'bs_date' : 'number';
    const shown = columns.filter((column) => !MERGED.includes(column.key));

    const tableColumns: DataTableColumn<Row>[] = shown.map((column) => ({
        key: column.key,
        header: column.key === 'bs_date' ? 'Date (BS / AD)' : column.label,
        align:
            column.type === 'money' || column.type === 'integer'
                ? 'right'
                : 'left',
        primary: column.key === 'number',
        hideOnMobile: ![
            'bs_date',
            'number',
            'type',
            'buyer',
            'vat',
            'total_sales',
            'total',
        ].includes(column.key),
        className: column.type === 'money' ? 'whitespace-nowrap' : undefined,
        cell: (row: Row) => {
            if (column.key === 'bs_date') {
                return (
                    <span className="whitespace-nowrap">
                        {bsDate(row.bs_date)}
                        <span className="block text-xs text-muted-foreground">
                            <DateTime value={row.ad_date as string} />
                        </span>
                    </span>
                );
            }

            if (column.key === 'invoice' && row.invoice_bs_date) {
                return (
                    <span className="whitespace-nowrap">
                        {text(row.invoice)}
                        <span className="block text-xs text-muted-foreground">
                            {bsDate(row.invoice_bs_date)}
                        </span>
                    </span>
                );
            }

            if (column.key === 'reason_code') {
                return <TextCell cell={humanize(text(row.reason_code))} />;
            }

            return column.type === 'money' ? (
                <MoneyCell cell={row[column.key]} />
            ) : (
                <TextCell cell={row[column.key]} />
            );
        },
    }));

    const moneyColumns = columns.filter((column) => column.type === 'money');

    return (
        <>
            <Head title="VAT register" />
            <Page>
                <PageHeader
                    title="VAT register"
                    description="Tax invoices, credit notes and debit notes by Nepali tax period, from the amounts stored when each document was numbered."
                    actions={
                        can.export ? (
                            <StepUpDownloadLink
                                href={exportUrl}
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                <Download />
                                Export CSV
                            </StepUpDownloadLink>
                        ) : (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span tabIndex={0}>
                                        <Button variant="outline" disabled>
                                            <Download />
                                            Export CSV
                                        </Button>
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>
                                    Exporting needs the export permission for
                                    this report.
                                </TooltipContent>
                            </Tooltip>
                        )
                    }
                />

                {tabs.length > 1 && (
                    <nav aria-label="Registers" className="flex gap-1 border-b">
                        {tabs.map((name) => (
                            <Link
                                key={name}
                                href={index.url({
                                    query: { tab: name, ...filters },
                                })}
                                aria-current={name === tab ? 'page' : undefined}
                                className={cn(
                                    '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
                                    name === tab
                                        ? 'border-primary text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {TAB_LABELS[name]}
                            </Link>
                        ))}
                    </nav>
                )}

                <SectionCard title="Period">
                    <form
                        onSubmit={apply}
                        className="flex flex-col gap-4 md:flex-row md:items-end"
                    >
                        <Field htmlFor="period-kind" label="Show">
                            <Select
                                value={kind}
                                onValueChange={(value) =>
                                    setKind(value as Kind)
                                }
                            >
                                <SelectTrigger
                                    id="period-kind"
                                    className="md:w-44"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="month">
                                        BS month
                                    </SelectItem>
                                    <SelectItem value="year">
                                        BS fiscal year
                                    </SelectItem>
                                    <SelectItem value="range">
                                        AD date range
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                        {kind === 'month' && (
                            <Field
                                htmlFor="period-month"
                                label="Month"
                                error={filterErrors.month}
                            >
                                <Select
                                    value={month || options.lastClosed}
                                    onValueChange={setMonth}
                                >
                                    <SelectTrigger
                                        id="period-month"
                                        className="md:w-48"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.months.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        )}
                        {kind === 'year' && (
                            <Field
                                htmlFor="period-year"
                                label="Fiscal year"
                                error={filterErrors.year}
                            >
                                <Select value={year} onValueChange={setYear}>
                                    <SelectTrigger
                                        id="period-year"
                                        className="md:w-40"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.years.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        )}
                        {kind === 'range' && (
                            <>
                                <Field
                                    htmlFor="period-from"
                                    label="From"
                                    error={filterErrors.from}
                                >
                                    <Input
                                        id="period-from"
                                        type="date"
                                        value={from}
                                        onChange={(event) =>
                                            setFrom(event.target.value)
                                        }
                                    />
                                </Field>
                                <Field
                                    htmlFor="period-to"
                                    label="To"
                                    error={filterErrors.to}
                                >
                                    <Input
                                        id="period-to"
                                        type="date"
                                        value={to}
                                        onChange={(event) =>
                                            setTo(event.target.value)
                                        }
                                    />
                                </Field>
                            </>
                        )}
                        <Button type="submit">Show</Button>
                    </form>
                </SectionCard>

                <SectionCard title={period.label}>
                    <DescriptionList
                        columns={3}
                        items={header.map(([label, value]) => ({
                            label,
                            value,
                        }))}
                    />
                </SectionCard>

                {warnings.withoutPan > 0 && (
                    <Alert>
                        <AlertTriangle className="text-warning-text" />
                        <AlertDescription>
                            {warnings.withoutPan === 1
                                ? '1 document is'
                                : `${warnings.withoutPan} documents are`}{' '}
                            to a business buyer without a PAN. Their PAN column
                            is blank; add the PAN to the client for later
                            documents.
                        </AlertDescription>
                    </Alert>
                )}
                {warnings.unconverted > 0 && (
                    <Alert>
                        <AlertTriangle className="text-warning-text" />
                        <AlertDescription>
                            {warnings.unconverted === 1
                                ? '1 document has'
                                : `${warnings.unconverted} documents have`}{' '}
                            no stored exchange rate into the register currency.
                            Their amounts are blank and left out of the totals.
                        </AlertDescription>
                    </Alert>
                )}

                {tab === 'register' && net && (
                    <section
                        aria-labelledby="return-figures"
                        className="flex flex-col gap-2"
                    >
                        <h2 id="return-figures" className="text-sm font-medium">
                            Return figures
                        </h2>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <StatCard
                                label="Net taxable sales"
                                value={<MoneyCell cell={net.taxable} />}
                            />
                            <StatCard
                                label="Net VAT on sales"
                                value={<MoneyCell cell={net.vat} />}
                            />
                            <StatCard
                                label="Exempt sales"
                                value={<MoneyCell cell={net.exempt} />}
                            />
                            <StatCard
                                label="Export sales"
                                value={<MoneyCell cell={net.export_value} />}
                            />
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Sales side only; purchases and input VAT are not in
                            this product.
                        </p>
                    </section>
                )}

                {monthRows.length > 0 && (
                    <SectionCard title="By month" flush>
                        <TotalsTable
                            rows={monthRows}
                            columns={moneyColumns}
                            labelKey={labelKey}
                        />
                    </SectionCard>
                )}

                <DataTable
                    caption={TAB_LABELS[tab]}
                    columns={tableColumns}
                    rows={rows.data}
                    getRowKey={(row) => `${text(row.type)}:${text(row.number)}`}
                    empty={
                        <EmptyState
                            icon={BookOpen}
                            title="No documents in this period"
                            description="Tax invoices appear once they are numbered; notes appear in the period they were issued."
                        />
                    }
                    footer={
                        <Pagination paginator={rows} itemLabel="documents" />
                    }
                />

                <SectionCard
                    title="Totals"
                    description="Every document in the period, not only this page."
                    flush
                >
                    <TotalsTable
                        rows={kindRows}
                        boldLast
                        columns={moneyColumns}
                        labelKey={labelKey}
                    />
                </SectionCard>
            </Page>
        </>
    );
}

function TotalsTable({
    rows,
    columns,
    labelKey,
    boldLast = false,
}: {
    rows: Row[];
    columns: Column[];
    labelKey: string;
    /** The last row is the net. */
    boldLast?: boolean;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-muted-foreground">
                        <th
                            className="px-4 py-2 text-left font-medium"
                            scope="col"
                        >
                            <span className="sr-only">Row</span>
                        </th>
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                className="px-4 py-2 text-right font-medium whitespace-nowrap"
                                scope="col"
                            >
                                {column.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr
                            key={text(row[labelKey])}
                            className={cn(
                                'border-b last:border-0',
                                boldLast &&
                                    i === rows.length - 1 &&
                                    'font-medium',
                            )}
                        >
                            <th
                                className="px-4 py-2 text-left font-medium whitespace-nowrap"
                                scope="row"
                            >
                                {text(row[labelKey])}
                            </th>
                            {columns.map((column) => (
                                <td
                                    key={column.key}
                                    className="px-4 py-2 text-right"
                                >
                                    <MoneyCell cell={row[column.key]} />
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
