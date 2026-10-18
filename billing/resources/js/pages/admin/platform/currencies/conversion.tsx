import { Head, router } from '@inertiajs/react';
import { ArrowRight, RefreshCcw } from 'lucide-react';
import { useState } from 'react';
import CurrencyController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/CurrencyController';
import PriceConversionController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/PriceConversionController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { EmptyState } from '@/components/app/empty-state';
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
import { formatRate } from '@/lib/exchange-rates';

type Preview = {
    key: string;
    label: string;
    changes: number;
    unchanged: number;
    manual: number;
    automatic: boolean;
    samples: {
        item: string;
        currency: string;
        period: string;
        before: number | null;
        after: number;
    }[];
};

type Props = {
    defaultCode: string;
    currencies: {
        code: string;
        exchange_rate: string;
        price_rounding: string;
        price_buffer_percent: string;
    }[];
    previews: Preview[];
};

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
                    description={`Prices in other currencies recalculated from the ${defaultCode} price with today's rates, each currency's buffer and rounding. Prices set manually are kept. Existing services and domains keep the amount they renew at.`}
                />

                <SectionCard title="Rules in force" flush>
                    <Table>
                        <TableHeader className="bg-muted/40">
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-4 sm:pl-6">
                                    Currency
                                </TableHead>
                                <TableHead className="text-right">
                                    Rate per 1 {defaultCode}
                                </TableHead>
                                <TableHead className="text-right">
                                    Buffer
                                </TableHead>
                                <TableHead className="pr-4 sm:pr-6">
                                    Rounding
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {currencies.map((currency) => (
                                <TableRow key={currency.code}>
                                    <TableCell className="pl-4 font-mono sm:pl-6">
                                        {currency.code}
                                    </TableCell>
                                    <TableCell className="text-right font-mono tabular-nums">
                                        {formatRate(currency.exchange_rate)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {Number(currency.price_buffer_percent)}%
                                    </TableCell>
                                    <TableCell className="pr-4 sm:pr-6">
                                        {currency.price_rounding}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </SectionCard>

                {previews.length === 0 && (
                    <EmptyState
                        icon={RefreshCcw}
                        title="Nothing to convert"
                        description="No installed module has prices that convert from the default currency."
                    />
                )}

                {previews.map((preview) => (
                    <SectionCard
                        key={preview.key}
                        title={preview.label}
                        description={
                            preview.changes === 0
                                ? 'All converted prices match the current rates.'
                                : `${preview.changes} price${preview.changes === 1 ? '' : 's'} would change · ${preview.unchanged} already match · ${preview.manual} set manually (kept)`
                        }
                        actions={
                            preview.automatic ? (
                                <StatusBadge
                                    status="active"
                                    label="Converts automatically"
                                />
                            ) : (
                                <StatusBadge
                                    status="inactive"
                                    label="Manual apply only"
                                />
                            )
                        }
                        flush
                        footer={
                            <Button
                                disabled={preview.changes === 0}
                                onClick={() => setApplying(preview)}
                            >
                                Apply {preview.changes} change
                                {preview.changes === 1 ? '' : 's'}
                            </Button>
                        }
                    >
                        {preview.samples.length > 0 && (
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
                                            <TableHead>Period</TableHead>
                                            <TableHead className="text-right">
                                                Now
                                            </TableHead>
                                            <TableHead className="sr-only">
                                                to
                                            </TableHead>
                                            <TableHead className="pr-4 text-right sm:pr-6">
                                                After
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {preview.samples.map(
                                            (sample, index) => (
                                                <TableRow
                                                    key={`${sample.item}-${sample.currency}-${sample.period}-${index}`}
                                                >
                                                    <TableCell className="pl-4 font-medium sm:pl-6">
                                                        {sample.item}
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
                                                    <TableCell className="w-6 text-muted-foreground">
                                                        <ArrowRight
                                                            className="size-4"
                                                            aria-hidden="true"
                                                        />
                                                    </TableCell>
                                                    <TableCell className="pr-4 text-right font-medium sm:pr-6">
                                                        <Money
                                                            amount={
                                                                sample.after
                                                            }
                                                            currency={
                                                                sample.currency
                                                            }
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            ),
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </SectionCard>
                ))}
            </Page>

            <ConfirmDialog
                open={applying !== null}
                onOpenChange={(open) => !open && setApplying(null)}
                title={`Update ${applying?.changes ?? 0} prices?`}
                description="New orders and invoices use the new prices. Existing services and domains keep the amount they renew at; prices set manually are not touched."
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
