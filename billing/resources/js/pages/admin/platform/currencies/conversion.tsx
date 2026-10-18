import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    RefreshCcw,
    Settings2,
} from 'lucide-react';
import { useState } from 'react';
import CurrencyController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/CurrencyController';
import PriceConversionController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/PriceConversionController';
import SettingsController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/SettingsController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { EmptyState } from '@/components/app/empty-state';
import { Head } from '@/components/app/head';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatRate, ratePair } from '@/lib/exchange-rates';
import { formatMoney } from '@/lib/money';

type Sample = {
    item: string;
    currency: string;
    period: string;
    before: number | null;
    after: number;
};

type Preview = {
    key: string;
    label: string;
    changes: number;
    unchanged: number;
    manual: number;
    automatic: boolean;
    settings_section: string | null;
    samples: Sample[];
};

type CurrencyRule = {
    id: number;
    code: string;
    exchange_rate: string;
    price_rounding: string;
    price_rounding_label: string;
    price_buffer_percent: string;
};

type Props = {
    defaultCode: string;
    currencies: CurrencyRule[];
    previews: Preview[];
};

function change(sample: Sample): string | null {
    if (sample.before === null || sample.before === 0) {
        return null;
    }

    const percent = ((sample.after - sample.before) / sample.before) * 100;
    const sign = percent > 0 ? '+' : percent < 0 ? '−' : '';

    return `${sign}${Math.abs(percent).toFixed(1)}%`;
}

export default function PriceConversion({
    defaultCode,
    currencies,
    previews,
}: Props) {
    const [applying, setApplying] = useState<Preview | null>(null);

    return (
        <>
            <Head title="Converted prices" />
            <Page>
                <PageHeader
                    back={{
                        href: CurrencyController.index(),
                        label: 'Currencies',
                    }}
                    title="Converted prices"
                    description={`Prices in other currencies recalculated from the ${defaultCode} price with today's rates, each currency's buffer and rounding rule. Locked prices are kept. Existing services and domains keep the amount they renew at.`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link
                                href={SettingsController.edit('exchange_rates')}
                            >
                                <Settings2 />
                                Rate settings
                            </Link>
                        </Button>
                    }
                />

                <SectionCard
                    title="Conversion rules"
                    description="Each currency's rate, buffer and rounding rule. Rounding applies from its unit up (1.00, or 10.00 for tens); smaller amounts keep their cents."
                    flush
                >
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader className="bg-muted/40">
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-4 sm:pl-6">
                                        Currency
                                    </TableHead>
                                    <TableHead>Rate</TableHead>
                                    <TableHead className="text-right">
                                        Buffer
                                    </TableHead>
                                    <TableHead>Rounding</TableHead>
                                    <TableHead className="pr-4 text-right sm:pr-6">
                                        <span className="sr-only">Edit</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {currencies.map((currency) => (
                                    <TableRow key={currency.code}>
                                        <TableCell className="pl-4 font-mono sm:pl-6">
                                            {currency.code}
                                        </TableCell>
                                        <TableCell
                                            className="whitespace-nowrap tabular-nums"
                                            title={`1 ${defaultCode} = ${formatRate(currency.exchange_rate)} ${currency.code}`}
                                        >
                                            {ratePair(
                                                currency.exchange_rate,
                                                defaultCode,
                                                currency.code,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {Number(
                                                currency.price_buffer_percent,
                                            )}
                                            %
                                        </TableCell>
                                        <TableCell>
                                            <span className="flex flex-wrap items-center gap-2">
                                                {currency.price_rounding_label}
                                                {currency.price_rounding ===
                                                    'none' && (
                                                    <StatusBadge
                                                        status="warning"
                                                        tone="warning"
                                                        label={`Prices like ${formatMoney(37891, currency.code)}`}
                                                    />
                                                )}
                                            </span>
                                        </TableCell>
                                        <TableCell className="pr-4 text-right sm:pr-6">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={CurrencyController.edit(
                                                        currency.id,
                                                    )}
                                                    aria-label={`Edit ${currency.code} rules`}
                                                >
                                                    Edit
                                                </Link>
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </SectionCard>

                {previews.length === 0 && (
                    <EmptyState
                        icon={RefreshCcw}
                        title="Nothing to convert"
                        description="No installed module has prices that convert from the default currency."
                    />
                )}

                {previews.map((preview) => {
                    const samples = [...preview.samples].sort((a, b) =>
                        a.currency.localeCompare(b.currency),
                    );
                    const autoBadge = (
                        <StatusBadge
                            status={preview.automatic ? 'active' : 'inactive'}
                            label={
                                preview.automatic
                                    ? 'Auto-update on: prices follow rate changes'
                                    : 'Auto-update off: prices change only when you apply'
                            }
                        />
                    );

                    return (
                        <SectionCard
                            key={preview.key}
                            title={preview.label}
                            description={
                                preview.changes === 0
                                    ? 'All converted prices match the current rates.'
                                    : `${preview.changes} price${preview.changes === 1 ? '' : 's'} would change · ${preview.unchanged} already match · ${preview.manual} locked (kept)`
                            }
                            actions={
                                preview.settings_section ? (
                                    <Link
                                        href={SettingsController.edit(
                                            preview.settings_section,
                                        )}
                                        className="rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        title="Change in Settings"
                                    >
                                        {autoBadge}
                                    </Link>
                                ) : (
                                    autoBadge
                                )
                            }
                            flush
                            footer={
                                preview.changes === 0 ? (
                                    <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
                                        <CheckCircle2
                                            className="size-4 text-success-text"
                                            aria-hidden="true"
                                        />
                                        Up to date
                                    </span>
                                ) : (
                                    <Button
                                        onClick={() => setApplying(preview)}
                                    >
                                        Apply {preview.changes} change
                                        {preview.changes === 1 ? '' : 's'}
                                    </Button>
                                )
                            }
                        >
                            {samples.length > 0 && (
                                <div className="overflow-x-auto">
                                    <Table>
                                        <caption className="sr-only">
                                            Sample of price changes for{' '}
                                            {preview.label}
                                        </caption>
                                        <TableHeader className="bg-muted/40">
                                            <TableRow className="hover:bg-transparent">
                                                <TableHead className="pl-4 sm:pl-6">
                                                    Item
                                                </TableHead>
                                                <TableHead>Currency</TableHead>
                                                <TableHead>Period</TableHead>
                                                <TableHead className="text-right">
                                                    Now
                                                </TableHead>
                                                <TableHead className="w-6">
                                                    <span className="sr-only">
                                                        to
                                                    </span>
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    After
                                                </TableHead>
                                                <TableHead className="pr-4 text-right sm:pr-6">
                                                    Change
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {samples.map((sample, index) => (
                                                <TableRow
                                                    key={`${sample.item}-${sample.currency}-${sample.period}-${index}`}
                                                >
                                                    <TableCell className="pl-4 font-medium sm:pl-6">
                                                        {sample.item}
                                                    </TableCell>
                                                    <TableCell className="font-mono">
                                                        {sample.currency}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {sample.period}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {sample.before ===
                                                        null ? (
                                                            <span className="text-muted-foreground">
                                                                Not priced
                                                            </span>
                                                        ) : (
                                                            <Money
                                                                amount={
                                                                    sample.before
                                                                }
                                                                currency={
                                                                    sample.currency
                                                                }
                                                            />
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        <ArrowRight
                                                            className="size-4"
                                                            aria-hidden="true"
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-right font-medium">
                                                        <Money
                                                            amount={
                                                                sample.after
                                                            }
                                                            currency={
                                                                sample.currency
                                                            }
                                                        />
                                                    </TableCell>
                                                    <TableCell className="pr-4 text-right text-muted-foreground tabular-nums sm:pr-6">
                                                        {change(sample) ??
                                                            'New'}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                    {preview.changes > samples.length && (
                                        <p className="flex items-center gap-1.5 border-t px-4 py-2 text-xs text-muted-foreground sm:px-6">
                                            <AlertTriangle
                                                className="size-3.5"
                                                aria-hidden="true"
                                            />
                                            Showing {samples.length} of{' '}
                                            {preview.changes} changes.
                                        </p>
                                    )}
                                </div>
                            )}
                        </SectionCard>
                    );
                })}
            </Page>

            <ConfirmDialog
                open={applying !== null}
                onOpenChange={(open) => !open && setApplying(null)}
                title={`Update ${applying?.changes ?? 0} prices?`}
                description="New orders and invoices use the new prices. Existing services and domains keep the amount they renew at; locked prices are not touched."
                confirmLabel="Update prices"
                onConfirm={() => {
                    if (applying) {
                        router.post(
                            PriceConversionController.apply.url(applying.key),
                            {},
                            { preserveScroll: true },
                        );
                    }

                    setApplying(null);
                }}
            />
        </>
    );
}

PriceConversion.layout = {
    breadcrumbs: [
        { title: 'Currencies', href: CurrencyController.index() },
        { title: 'Converted prices', href: PriceConversionController.show() },
    ],
};
