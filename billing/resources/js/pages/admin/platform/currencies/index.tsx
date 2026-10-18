import { Link, router } from '@inertiajs/react';
import {
    Coins,
    MoreHorizontal,
    Plus,
    RefreshCw,
    Settings2,
} from 'lucide-react';
import { Head } from '@/components/app/head';
import { useState } from 'react';
import CurrencyController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/CurrencyController';
import ExchangeRateController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/ExchangeRateController';
import PriceConversionController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/PriceConversionController';
import SettingsController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/SettingsController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { RateChange } from '@/components/currencies/rate-change';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/money';
import { daysSince, formatRate, ratePair } from '@/lib/exchange-rates';
import type { RateReview } from '@/lib/exchange-rates';

type CurrencyRow = {
    id: number;
    code: string;
    name: string;
    prefix: string;
    suffix: string;
    decimals: number;
    format: string;
    exchange_rate: string;
    is_default: boolean;
    rate_source: string;
    rate_source_label: string;
    rate_updated_at: string | null;
    rate_checked_at: string | null;
    is_stale: boolean;
    clients_count: number;
    /** Why Make default / Delete can't succeed (shown on the disabled action), or null. */
    default_blocked: string | null;
    delete_blocked: string | null;
};

/** A menu action with the reason it is unavailable underneath. */
function ActionLabel({
    label,
    reason,
}: {
    label: string;
    reason: string | null;
}) {
    return (
        <span className="grid gap-0.5">
            <span>{label}</span>
            {reason && (
                <span className="text-xs text-muted-foreground">{reason}</span>
            )}
        </span>
    );
}

type Props = {
    currencies: CurrencyRow[];
    reviews: RateReview[];
    staleAfterDays: number;
    manualStaleAfterDays: number;
    canManageRates: boolean;
    hasAutoRates: boolean;
    /** When automatic rates are next fetched (ISO), or null when every rate is manual. */
    nextUpdate: string | null;
};

/** 1,234,567.89 in the currency's own display, e.g. "Rs 12,34,567.89". */
const SAMPLE_AMOUNT = 123456789;

export default function CurrenciesIndex({
    currencies,
    reviews,
    canManageRates,
    nextUpdate,
    hasAutoRates,
}: Props) {
    const [deleting, setDeleting] = useState<CurrencyRow | null>(null);
    const [promoting, setPromoting] = useState<CurrencyRow | null>(null);
    const [refreshing, setRefreshing] = useState(false);
    const [deciding, setDeciding] = useState<number | null>(null);
    const base = currencies.find((currency) => currency.is_default);

    const refresh = () =>
        router.post(
            ExchangeRateController.refresh.url(),
            {},
            {
                preserveScroll: true,
                onStart: () => setRefreshing(true),
                onFinish: () => setRefreshing(false),
            },
        );

    const decide = (review: RateReview, approve: boolean) =>
        router.post(
            approve
                ? ExchangeRateController.approve.url(review.id)
                : ExchangeRateController.reject.url(review.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setDeciding(review.id),
                onFinish: () => setDeciding(null),
            },
        );

    const staleLabel = (currency: CurrencyRow): string => {
        const days = daysSince(
            currency.rate_source === 'manual'
                ? currency.rate_updated_at
                : currency.rate_checked_at,
        );

        return days === null
            ? 'Never updated'
            : `Not updated for ${days} day${days === 1 ? '' : 's'}`;
    };

    const staleBadge = (currency: CurrencyRow) =>
        currency.is_stale ? (
            <StatusBadge
                status="stale"
                label={staleLabel(currency)}
                tone="warning"
            />
        ) : null;

    const columns: DataTableColumn<CurrencyRow>[] = [
        {
            key: 'code',
            header: 'Currency',
            primary: true,
            cell: (currency) => (
                <span className="flex flex-wrap items-center gap-2">
                    <span className="font-mono">{currency.code}</span>
                    <span className="font-normal text-muted-foreground">
                        {currency.name}
                    </span>
                    {currency.is_default && (
                        <Badge variant="secondary">Default</Badge>
                    )}
                    {/* The source column is hidden on phones; keep the warning visible. */}
                    <span className="md:hidden">{staleBadge(currency)}</span>
                </span>
            ),
        },
        {
            key: 'sample',
            header: 'Shown as',
            hideOnMobile: true,
            cell: (currency) => (
                <span className="whitespace-nowrap text-muted-foreground tabular-nums">
                    {formatMoney(SAMPLE_AMOUNT, currency.code)}
                </span>
            ),
        },
        {
            key: 'rate',
            header: 'Rate',
            align: 'right',
            cell: (currency) =>
                currency.is_default || !base ? (
                    <span className="text-muted-foreground">Default</span>
                ) : (
                    <span
                        className="whitespace-nowrap tabular-nums"
                        title={`1 ${base.code} = ${formatRate(currency.exchange_rate)} ${currency.code}`}
                    >
                        {ratePair(
                            currency.exchange_rate,
                            base.code,
                            currency.code,
                        )}
                    </span>
                ),
        },
        {
            key: 'source',
            header: 'Source',
            hideOnMobile: true,
            cell: (currency) =>
                currency.is_default ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    <span className="flex flex-wrap items-center gap-2">
                        <span>{currency.rate_source_label}</span>
                        {staleBadge(currency)}
                    </span>
                ),
        },
        {
            key: 'updated',
            header: 'Rate changed',
            hideOnMobile: true,
            cell: (currency) => (
                <DateTime value={currency.rate_updated_at} relative />
            ),
        },
        {
            key: 'clients',
            header: 'Clients',
            align: 'right',
            hideOnMobile: true,
            cell: (currency) => (
                <span className="tabular-nums">{currency.clients_count}</span>
            ),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            align: 'right',
            cell: (currency) => (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Actions for ${currency.code}`}
                        >
                            <MoreHorizontal />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-64">
                        <DropdownMenuItem asChild>
                            <Link href={CurrencyController.edit(currency.id)}>
                                Edit
                            </Link>
                        </DropdownMenuItem>
                        {canManageRates && (
                            <>
                                <DropdownMenuItem
                                    disabled={currency.default_blocked !== null}
                                    onSelect={() => setPromoting(currency)}
                                >
                                    <ActionLabel
                                        label="Make default"
                                        reason={currency.default_blocked}
                                    />
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    disabled={currency.delete_blocked !== null}
                                    onSelect={() => setDeleting(currency)}
                                >
                                    <ActionLabel
                                        label="Delete"
                                        reason={currency.delete_blocked}
                                    />
                                </DropdownMenuItem>
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            ),
        },
    ];

    return (
        <>
            <Head title="Currencies" />
            <Page>
                <PageHeader
                    title="Currencies"
                    description={
                        <>
                            How amounts are shown in each currency, and the
                            exchange rates used to convert prices.
                            {nextUpdate && (
                                <>
                                    {' '}
                                    Next automatic update{' '}
                                    <DateTime value={nextUpdate} relative />.
                                </>
                            )}
                        </>
                    }
                    actions={
                        <>
                            {canManageRates && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={SettingsController.edit(
                                            'exchange_rates',
                                        )}
                                    >
                                        <Settings2 />
                                        Rate settings
                                    </Link>
                                </Button>
                            )}
                            {canManageRates && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={PriceConversionController.show()}
                                    >
                                        Converted prices
                                    </Link>
                                </Button>
                            )}
                            {canManageRates && hasAutoRates && (
                                <Button
                                    variant="outline"
                                    onClick={refresh}
                                    disabled={refreshing}
                                >
                                    {refreshing ? <Spinner /> : <RefreshCw />}
                                    Update rates now
                                </Button>
                            )}
                            {canManageRates && (
                                <Button asChild>
                                    <Link href={CurrencyController.create()}>
                                        <Plus />
                                        New currency
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                {reviews.length > 0 && (
                    <SectionCard
                        title="Rate changes waiting for approval"
                        description="These fetched rates moved more than the allowed daily change, so they were not applied. Prices and invoices keep using the current rate until you approve."
                        flush
                        className="border-warning/40"
                    >
                        <ul className="divide-y">
                            {reviews.map((review) => (
                                <li
                                    key={review.id}
                                    className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:px-6"
                                >
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <span className="flex flex-wrap items-center gap-2 font-medium">
                                            <span className="font-mono">
                                                {review.currency}
                                            </span>
                                            <RateChange
                                                review={review}
                                                defaultCode={base?.code ?? ''}
                                            />
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {review.source} · published{' '}
                                            <DateTime
                                                value={review.effective_at}
                                            />
                                        </span>
                                    </div>
                                    {canManageRates && (
                                        <div className="flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    deciding === review.id
                                                }
                                                onClick={() =>
                                                    decide(review, false)
                                                }
                                            >
                                                Keep current rate
                                            </Button>
                                            <Button
                                                size="sm"
                                                disabled={
                                                    deciding === review.id
                                                }
                                                onClick={() =>
                                                    decide(review, true)
                                                }
                                            >
                                                {deciding === review.id && (
                                                    <Spinner />
                                                )}
                                                Approve new rate
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </SectionCard>
                )}

                <DataTable
                    caption="Currencies"
                    columns={columns}
                    rows={currencies}
                    getRowKey={(currency) => currency.id}
                    rowHref={(currency) => CurrencyController.edit(currency.id)}
                    empty={
                        <EmptyState
                            icon={Coins}
                            title="No currencies yet"
                            description="Add the currency you invoice in first; it becomes the default."
                        />
                    }
                />
            </Page>

            <ConfirmDialog
                open={promoting !== null}
                onOpenChange={(open) => !open && setPromoting(null)}
                title={`Make ${promoting?.code ?? ''} the default currency?`}
                description={`Every other exchange rate is recalculated against ${promoting?.code ?? 'it'}, and converted prices follow the new rates. This is only possible while no invoices or payments exist.`}
                confirmLabel="Make default"
                onConfirm={() => {
                    if (promoting) {
                        router.post(
                            CurrencyController.makeDefault.url(promoting.id),
                            {},
                            { preserveScroll: true },
                        );
                    }

                    setPromoting(null);
                }}
            />

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete ${deleting?.code ?? ''}?`}
                description="Currencies used by clients, prices, invoices or payments cannot be deleted."
                confirmLabel="Delete currency"
                action={
                    deleting
                        ? CurrencyController.destroy(deleting.id)
                        : undefined
                }
            />
        </>
    );
}

CurrenciesIndex.layout = {
    breadcrumbs: [{ title: 'Currencies', href: CurrencyController.index() }],
};
