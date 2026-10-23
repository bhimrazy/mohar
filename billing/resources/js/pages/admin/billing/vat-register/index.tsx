import { Link, router } from '@inertiajs/react';
import { AlertTriangle, BookOpen, Download } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
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
import { SelectField } from '@/components/app/select-field';
import { StatCard } from '@/components/app/stat-card';
import { StepUpDownloadLink } from '@/components/app/step-up-download-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { currencySymbol, formatMoney } from '@/lib/money';
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
    submitted: Partial<Record<'month' | 'year' | 'from' | 'to', string>>;
    options: {
        months: SelectOption[];
        years: SelectOption[];
        lastClosed: string;
    };
    documents: {
        total: number;
        pageable: number;
        hasExport: boolean;
        currency: string;
    };
    warnings: { withoutPan: number; unconverted: number; unclassified: number };
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

function plural(count: number): string {
    return count === 1 ? '1 document is' : `${count} documents are`;
}

/** Text columns wrap within a bounded width, so the amount columns stay on screen. */
const WRAP = 'min-w-32 max-w-52 whitespace-normal';

const KIND_OPTIONS: SelectOption[] = [
    { value: 'month', label: 'BS month' },
    { value: 'year', label: 'BS fiscal year' },
    { value: 'range', label: 'AD date range' },
];

/** A main value with a smaller secondary line under it. */
function Stack({ main, sub }: { main: ReactNode; sub?: ReactNode }) {
    return (
        <span className="block">
            {main}
            {sub ? (
                <span className="block text-xs text-muted-foreground">
                    {sub}
                </span>
            ) : null}
        </span>
    );
}

/** "$20.69 · $1 = Rs 151.94": a document in another currency, the rate to at most 4 decimals. */
function foreign(row: Row, register: string): string | null {
    if (!isMoney(row.original_amount) || !row.currency) {
        return null;
    }

    const amount = formatMoney(
        row.original_amount.amount,
        row.original_amount.currency,
    );
    const rate = Number(text(row.rate)).toLocaleString('en', {
        maximumFractionDigits: 4,
    });

    return row.rate
        ? `${amount} · ${currencySymbol(text(row.currency))}1 = ${currencySymbol(register)} ${rate}`
        : amount;
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
    submitted,
    options,
    documents,
    warnings,
    can,
}: Props) {
    // After a refused period the page shows the default month; the picker keeps what was asked for, beside its error.
    const asked = { ...filters, ...submitted };
    const [kind, setKind] = useState<Kind>(
        submitted.year
            ? 'year'
            : submitted.from || submitted.to
              ? 'range'
              : submitted.month
                ? 'month'
                : period.kind,
    );
    const [month, setMonth] = useState(asked.month ?? options.lastClosed);
    const [year, setYear] = useState(
        asked.year ?? options.years[0]?.value ?? '',
    );
    const [from, setFrom] = useState(asked.from ?? period.from);
    const [to, setTo] = useState(asked.to ?? period.to);

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
    const money = (key: string, header: string, mobile = false) => ({
        key,
        header,
        align: 'right' as const,
        hideOnMobile: !mobile,
        className: 'whitespace-nowrap',
        cell: (row: Row) => <MoneyCell cell={row[key]} />,
    });
    const date: DataTableColumn<Row> = {
        key: 'date',
        header: 'Date (BS / AD)',
        cell: (row) => (
            <Stack
                main={bsDate(row.bs_date)}
                sub={<DateTime value={text(row.ad_date)} />}
            />
        ),
    };
    const buyer: DataTableColumn<Row> = {
        key: 'buyer',
        className: WRAP,
        header: 'Buyer',
        cell: (row) => (
            <Stack
                main={<TextCell cell={row.buyer} />}
                sub={row.pan ? `PAN ${text(row.pan)}` : null}
            />
        ),
    };
    // Secondary facts sit under the main one, so Total, Taxable and VAT stay on screen at 1440 px; the CSV keeps
    // every column.
    const tableColumns: DataTableColumn<Row>[] =
        tab === 'register'
            ? [
                  date,
                  {
                      key: 'number',
                      header: 'Document',
                      primary: true,
                      cell: (row) => (
                          <Stack
                              main={text(row.number)}
                              sub={
                                  text(row.type) +
                                  (row.reference
                                      ? ` · against ${text(row.reference)} (${bsDate(row.reference_bs_date)})`
                                      : '')
                              }
                          />
                      ),
                  },
                  buyer,
                  {
                      key: 'goods',
                      className: WRAP,
                      header: 'Goods or service',
                      hideOnMobile: true,
                      cell: (row) => (
                          <Stack
                              main={<TextCell cell={row.goods} />}
                              sub={
                                  row.quantity !== null
                                      ? `${text(row.quantity)} × ${text(row.unit)}`
                                      : null
                              }
                          />
                      ),
                  },
                  {
                      ...money('total_sales', 'Total sales', true),
                      cell: (row) => (
                          <Stack
                              main={<MoneyCell cell={row.total_sales} />}
                              sub={foreign(row, documents.currency)}
                          />
                      ),
                  },
                  money('exempt', 'Exempt'),
                  money('taxable', 'Taxable'),
                  money('vat', 'VAT', true),
                  ...(documents.hasExport
                      ? [
                            {
                                ...money('export_value', 'Export'),
                                cell: (row: Row) => (
                                    <Stack
                                        main={
                                            <MoneyCell
                                                cell={row.export_value}
                                            />
                                        }
                                        sub={
                                            row.export_country
                                                ? text(row.export_country)
                                                : null
                                        }
                                    />
                                ),
                            },
                        ]
                      : []),
              ]
            : [
                  {
                      key: 'number',
                      header: 'Note',
                      primary: true,
                      cell: (row) => (
                          <Stack main={text(row.number)} sub={text(row.type)} />
                      ),
                  },
                  date,
                  {
                      key: 'invoice',
                      header: 'Invoice',
                      cell: (row) => (
                          <Stack
                              main={text(row.invoice)}
                              sub={bsDate(row.invoice_bs_date)}
                          />
                      ),
                  },
                  buyer,
                  {
                      key: 'reason',
                      className: WRAP,
                      header: 'Reason',
                      hideOnMobile: true,
                      cell: (row) => (
                          <Stack
                              main={humanize(text(row.reason_code))}
                              sub={text(row.reason)}
                          />
                      ),
                  },
                  money('taxable', 'Taxable'),
                  money('exempt', 'Exempt'),
                  money('vat', 'VAT', true),
                  money('total', 'Total', true),
                  {
                      key: 'settlement',
                      className: WRAP,
                      header: 'Settlement',
                      hideOnMobile: true,
                      cell: (row) => (
                          <Stack
                              main={<TextCell cell={row.settlement} />}
                              sub={row.created_by ? text(row.created_by) : null}
                          />
                      ),
                  },
              ];

    const moneyColumns = columns.filter(
        (column) =>
            column.type === 'money' &&
            column.key !== 'original_amount' &&
            (documents.hasExport || column.key !== 'export_value'),
    );
    const notices = [
        warnings.withoutPan > 0 &&
            `${plural(warnings.withoutPan)} to a business buyer without a PAN. Their PAN column is blank; add the PAN to the client for later documents.`,
        warnings.unconverted > 0 &&
            `${plural(warnings.unconverted)} without a stored exchange rate into the register currency. Their amounts are blank and left out of the totals.`,
        warnings.unclassified > 0 &&
            `${plural(warnings.unclassified)} with tax that is not yet split into Nepal VAT and other tax. Their tax is left out of VAT; ask the person who runs the system to run billing:fiscal-vat-split.`,
    ].filter((notice): notice is string => typeof notice === 'string');

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
                            <SelectField
                                id="period-kind"
                                className="md:w-44"
                                options={KIND_OPTIONS}
                                value={kind}
                                onValueChange={(value) =>
                                    setKind(value as Kind)
                                }
                            />
                        </Field>
                        {kind === 'month' && (
                            <Field
                                htmlFor="period-month"
                                label="Month"
                                error={filterErrors.month}
                            >
                                <SelectField
                                    id="period-month"
                                    className="md:w-48"
                                    options={options.months}
                                    value={month}
                                    onValueChange={setMonth}
                                />
                            </Field>
                        )}
                        {kind === 'year' && (
                            <Field
                                htmlFor="period-year"
                                label="Fiscal year"
                                error={filterErrors.year}
                            >
                                <SelectField
                                    id="period-year"
                                    className="md:w-40"
                                    options={options.years}
                                    value={year}
                                    onValueChange={setYear}
                                />
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

                {notices.map((notice) => (
                    <Alert key={notice}>
                        <AlertTriangle className="text-warning-text" />
                        <AlertDescription>{notice}</AlertDescription>
                    </Alert>
                ))}
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
                        <>
                            <Pagination
                                paginator={rows}
                                itemLabel="documents"
                            />
                            {documents.total > documents.pageable && (
                                <p className="px-4 pb-3 text-xs text-muted-foreground">
                                    Only the first{' '}
                                    {documents.pageable.toLocaleString('en')} of{' '}
                                    {documents.total.toLocaleString('en')}{' '}
                                    documents can be paged here. Choose a
                                    shorter period, or export the CSV.
                                </p>
                            )}
                        </>
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
        <>
            {/* Phones: one block per row, so no column is cut off. */}
            <div className="divide-y md:hidden">
                {rows.map((row, i) => (
                    <dl
                        key={text(row[labelKey])}
                        className={cn(
                            'grid grid-cols-2 gap-x-4 gap-y-1 px-4 py-3 text-sm',
                            boldLast && i === rows.length - 1 && 'font-medium',
                        )}
                    >
                        <dt className="col-span-2 font-medium">
                            {text(row[labelKey])}
                        </dt>
                        {columns.map((column) => (
                            <div key={column.key} className="contents">
                                <dt className="text-muted-foreground">
                                    {column.label}
                                </dt>
                                <dd className="text-right">
                                    <MoneyCell cell={row[column.key]} />
                                </dd>
                            </div>
                        ))}
                    </dl>
                ))}
            </div>
            <div className="hidden overflow-x-auto md:block">
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
        </>
    );
}
