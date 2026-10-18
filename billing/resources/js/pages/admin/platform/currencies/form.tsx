import { Form, Link, router } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import { Head } from '@/components/app/head';
import CurrencyController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/CurrencyController';
import ExchangeRateController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/ExchangeRateController';
import { DateTime } from '@/components/app/date';
import { Field } from '@/components/app/field';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { SelectField } from '@/components/app/select-field';
import { StatusBadge } from '@/components/app/status-badge';
import { TimeSeriesChart } from '@/components/app/time-series-chart';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { UnsavedChangesGuard } from '@/hooks/use-unsaved-changes-warning';
import { formatPercentChange, formatRate } from '@/lib/exchange-rates';
import type { RateReview } from '@/lib/exchange-rates';
import { groupNumber } from '@/lib/money';

type Currency = {
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
    price_rounding: string;
    price_buffer_percent: string;
};

type Source = { key: string; label: string; description: string };

type Props = {
    currency?: Currency;
    formats: string[];
    decimals: number[];
    defaultCode: string;
    sources?: Source[];
    roundings?: { value: string; label: string }[];
    history?: { x: string; y: number }[];
    review?: RateReview | null;
    canManageRates?: boolean;
};

const FORMAT_LABELS: Record<string, string> = {
    '1234.56': '1234567.89 (no grouping)',
    '1,234.56': '1,234,567.89',
    '1.234,56': '1.234.567,89',
    '1 234,56': '1 234 567,89',
    '1,23,456.78': '12,34,567.89 (Indian lakh)',
};

const MANUAL = 'manual';

export default function CurrencyForm({
    currency,
    formats,
    decimals,
    defaultCode,
    sources = [],
    roundings = [],
    history = [],
    review = null,
    canManageRates = false,
}: Props) {
    const action = currency
        ? CurrencyController.update.form(currency.id)
        : CurrencyController.store.form();
    const [display, setDisplay] = useState({
        prefix: currency?.prefix ?? '',
        suffix: currency?.suffix ?? '',
        format: currency?.format ?? '1,234.56',
    });
    const [source, setSource] = useState(currency?.rate_source ?? MANUAL);
    const [deciding, setDeciding] = useState(false);
    const selectedSource = sources.find((item) => item.key === source);
    const sample = (minor: number) =>
        `${minor < 0 ? '−' : ''}${display.prefix}${groupNumber(Math.abs(minor), 2, display.format)}${display.suffix}`;

    const decide = (approve: boolean) =>
        review &&
        router.post(
            approve
                ? ExchangeRateController.approve.url(review.id)
                : ExchangeRateController.reject.url(review.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setDeciding(true),
                onFinish: () => setDeciding(false),
            },
        );

    return (
        <>
            <Head title={currency ? `Edit ${currency.code}` : 'New currency'} />
            <Page size="narrow">
                <PageHeader
                    back={{
                        href: CurrencyController.index(),
                        label: 'Currencies',
                    }}
                    title={
                        currency
                            ? `${currency.code} · ${currency.name}`
                            : 'New currency'
                    }
                />

                {review && (
                    <SectionCard
                        title="Rate change waiting for approval"
                        description={`${review.source} published a rate that moved more than the allowed daily change. The current rate stays until you decide.`}
                        className="border-warning/40"
                        footer={
                            canManageRates && (
                                <>
                                    <Button
                                        variant="outline"
                                        disabled={deciding}
                                        onClick={() => decide(false)}
                                    >
                                        Keep current rate
                                    </Button>
                                    <Button
                                        disabled={deciding}
                                        onClick={() => decide(true)}
                                    >
                                        {deciding && <Spinner />}
                                        Approve new rate
                                    </Button>
                                </>
                            )
                        }
                    >
                        <p className="flex flex-wrap items-center gap-2 font-mono text-lg tabular-nums">
                            {formatRate(review.current_rate)}
                            <ArrowRight
                                className="size-4 text-muted-foreground"
                                aria-label="to"
                            />
                            {formatRate(review.proposed_rate)}
                            <StatusBadge
                                status="review"
                                tone="warning"
                                label={formatPercentChange(
                                    review.change_percent,
                                )}
                            />
                        </p>
                    </SectionCard>
                )}

                <Form {...action}>
                    {({ errors, processing, isDirty }) => (
                        <>
                            <UnsavedChangesGuard
                                dirty={isDirty && !processing}
                            />
                            <SectionCard
                                title="Display"
                                description="How amounts in this currency appear on pages, invoices, PDFs and email."
                                footer={
                                    <>
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={CurrencyController.index()}
                                            >
                                                Cancel
                                            </Link>
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {currency
                                                ? 'Save display'
                                                : 'Create currency'}
                                        </Button>
                                    </>
                                }
                            >
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field
                                        htmlFor="code"
                                        label="ISO code"
                                        error={errors.code}
                                        hint="Three letters, e.g. EUR."
                                    >
                                        <Input
                                            id="code"
                                            name="code"
                                            maxLength={3}
                                            className="font-mono uppercase"
                                            defaultValue={currency?.code ?? ''}
                                            aria-invalid={Boolean(errors.code)}
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="name"
                                        label="Name"
                                        error={errors.name}
                                    >
                                        <Input
                                            id="name"
                                            name="name"
                                            defaultValue={currency?.name ?? ''}
                                            aria-invalid={Boolean(errors.name)}
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="prefix"
                                        label="Symbol before amounts"
                                        error={errors.prefix}
                                        hint="Add a space after it to keep it apart from the number."
                                        optional
                                    >
                                        <Input
                                            id="prefix"
                                            name="prefix"
                                            defaultValue={
                                                currency?.prefix ?? ''
                                            }
                                            placeholder="$"
                                            onChange={(event) =>
                                                setDisplay({
                                                    ...display,
                                                    prefix: event.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="suffix"
                                        label="Symbol after amounts"
                                        error={errors.suffix}
                                        optional
                                    >
                                        <Input
                                            id="suffix"
                                            name="suffix"
                                            defaultValue={
                                                currency?.suffix ?? ''
                                            }
                                            placeholder="e.g. kr"
                                            onChange={(event) =>
                                                setDisplay({
                                                    ...display,
                                                    suffix: event.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="format"
                                        label="Number format"
                                        error={errors.format}
                                    >
                                        <SelectField
                                            id="format"
                                            name="format"
                                            value={display.format}
                                            onValueChange={(format) =>
                                                setDisplay({
                                                    ...display,
                                                    format,
                                                })
                                            }
                                            options={formats.map((value) => ({
                                                value,
                                                label:
                                                    FORMAT_LABELS[value] ??
                                                    value,
                                            }))}
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="decimals"
                                        label="Decimal places"
                                        error={errors.decimals}
                                        hint="Amounts are stored in hundredths, so only 2-decimal currencies are supported for now."
                                    >
                                        <SelectField
                                            id="decimals"
                                            name="decimals"
                                            defaultValue={String(
                                                currency?.decimals ??
                                                    decimals[0],
                                            )}
                                            disabled={decimals.length === 1}
                                            options={decimals.map((value) => ({
                                                value: String(value),
                                                label: String(value),
                                            }))}
                                        />
                                    </Field>
                                    {!currency && (
                                        <Field
                                            htmlFor="exchange_rate"
                                            label={`Starting exchange rate (per 1 ${defaultCode})`}
                                            error={errors.exchange_rate}
                                            hint="Up to 12 decimal places. Choose an automatic source after creating the currency."
                                            className="sm:col-span-2"
                                        >
                                            <Input
                                                id="exchange_rate"
                                                name="exchange_rate"
                                                inputMode="decimal"
                                                className="font-mono"
                                                defaultValue="1"
                                                aria-invalid={Boolean(
                                                    errors.exchange_rate,
                                                )}
                                            />
                                        </Field>
                                    )}
                                    <div className="rounded-lg border bg-muted/40 px-4 py-3 sm:col-span-2">
                                        <p className="text-xs text-muted-foreground">
                                            Preview
                                        </p>
                                        <p
                                            className="mt-1 font-medium tabular-nums"
                                            aria-live="polite"
                                        >
                                            {sample(123456789)}
                                            <span className="mx-2 text-muted-foreground">
                                                ·
                                            </span>
                                            {sample(-120000)}
                                            <span className="mx-2 text-muted-foreground">
                                                ·
                                            </span>
                                            {sample(999)}
                                        </p>
                                    </div>
                                </div>
                            </SectionCard>
                        </>
                    )}
                </Form>

                {currency && !currency.is_default && (
                    <Form
                        {...ExchangeRateController.update.form(currency.id)}
                        options={{ preserveScroll: true }}
                    >
                        {({ errors, processing, isDirty }) => (
                            <>
                                <UnsavedChangesGuard
                                    dirty={isDirty && !processing}
                                />
                                <SectionCard
                                    title="Exchange rate and converted prices"
                                    description={`Units of ${currency.code} per 1 ${defaultCode}. Changes here move converted prices, so they need your password.`}
                                    footer={
                                        canManageRates && (
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                {processing && <Spinner />}
                                                Save rate settings
                                            </Button>
                                        )
                                    }
                                >
                                    <fieldset
                                        disabled={!canManageRates}
                                        className="grid gap-6 sm:grid-cols-2"
                                    >
                                        <Field
                                            htmlFor="rate_source"
                                            label="Rate source"
                                            error={errors.rate_source}
                                            hint={
                                                selectedSource?.description ??
                                                'You enter the rate yourself.'
                                            }
                                        >
                                            <SelectField
                                                id="rate_source"
                                                name="rate_source"
                                                value={source}
                                                onValueChange={setSource}
                                                disabled={!canManageRates}
                                                options={[
                                                    {
                                                        value: MANUAL,
                                                        label: 'Manual',
                                                    },
                                                    ...sources.map((item) => ({
                                                        value: item.key,
                                                        label: item.label,
                                                    })),
                                                ]}
                                            />
                                        </Field>
                                        {source === MANUAL ? (
                                            <Field
                                                htmlFor="exchange_rate"
                                                label={`Rate per 1 ${defaultCode}`}
                                                error={errors.exchange_rate}
                                                hint={
                                                    currency.is_stale
                                                        ? 'This rate has not changed for a long time. Check that it is still right.'
                                                        : 'Up to 12 decimal places.'
                                                }
                                            >
                                                <Input
                                                    id="exchange_rate"
                                                    name="exchange_rate"
                                                    inputMode="decimal"
                                                    className="font-mono"
                                                    defaultValue={formatRate(
                                                        currency.exchange_rate,
                                                    ).replace(/,/g, '')}
                                                    aria-invalid={Boolean(
                                                        errors.exchange_rate,
                                                    )}
                                                />
                                            </Field>
                                        ) : (
                                            <div className="grid content-start gap-2">
                                                <span className="text-sm font-medium">
                                                    Current rate
                                                </span>
                                                <span className="flex flex-wrap items-center gap-2 font-mono tabular-nums">
                                                    {formatRate(
                                                        currency.exchange_rate,
                                                    )}
                                                    {currency.is_stale && (
                                                        <StatusBadge
                                                            status="stale"
                                                            tone="warning"
                                                            label="Stale"
                                                        />
                                                    )}
                                                </span>
                                                <span className="text-sm text-muted-foreground">
                                                    Last fetched{' '}
                                                    <DateTime
                                                        value={
                                                            currency.rate_checked_at
                                                        }
                                                        relative
                                                    />
                                                </span>
                                            </div>
                                        )}
                                        <Field
                                            htmlFor="price_rounding"
                                            label="Round converted prices"
                                            error={errors.price_rounding}
                                        >
                                            <SelectField
                                                id="price_rounding"
                                                name="price_rounding"
                                                defaultValue={
                                                    currency.price_rounding
                                                }
                                                disabled={!canManageRates}
                                                options={roundings}
                                            />
                                        </Field>
                                        <Field
                                            htmlFor="price_buffer_percent"
                                            label="Exchange buffer (%)"
                                            error={errors.price_buffer_percent}
                                            hint="Added to converted prices to cover rate swings and fees, e.g. 2.5."
                                            optional
                                        >
                                            <Input
                                                id="price_buffer_percent"
                                                name="price_buffer_percent"
                                                inputMode="decimal"
                                                defaultValue={String(
                                                    Number(
                                                        currency.price_buffer_percent,
                                                    ),
                                                )}
                                                aria-invalid={Boolean(
                                                    errors.price_buffer_percent,
                                                )}
                                            />
                                        </Field>
                                    </fieldset>
                                </SectionCard>
                            </>
                        )}
                    </Form>
                )}

                {currency && !currency.is_default && history.length > 0 && (
                    <SectionCard
                        title="Rate history"
                        description="The rate in force at the end of each day, last 90 days."
                    >
                        <TimeSeriesChart
                            series={[
                                {
                                    name: `${currency.code} per 1 ${defaultCode}`,
                                    points: history,
                                },
                            ]}
                            yScale="fit"
                            showLegend={false}
                            label={`${currency.code} per 1 ${defaultCode}, last 90 days`}
                        />
                    </SectionCard>
                )}

                {currency?.is_default && (
                    <p className="text-sm text-muted-foreground">
                        This is the default currency: its rate is always 1 and
                        every other rate is quoted against it.
                    </p>
                )}
            </Page>
        </>
    );
}

CurrencyForm.layout = {
    breadcrumbs: [{ title: 'Currencies', href: CurrencyController.index() }],
};
