import { Form, Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import CurrencyController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/CurrencyController';
import { Field } from '@/components/app/field';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { SelectField } from '@/components/app/select-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

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
};

type Props = {
    currency?: Currency;
    formats: string[];
    decimals: number[];
    defaultCode: string;
};

export default function CurrencyForm({
    currency,
    formats,
    decimals,
    defaultCode,
}: Props) {
    const action = currency
        ? CurrencyController.update.form(currency.id)
        : CurrencyController.store.form();

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

                <Form {...action}>
                    {({ errors, processing }) => (
                        <SectionCard
                            title="Currency"
                            footer={
                                <>
                                    <Button variant="outline" asChild>
                                        <Link href={CurrencyController.index()}>
                                            Cancel
                                        </Link>
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {currency
                                            ? 'Save currency'
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
                                    label="Prefix"
                                    error={errors.prefix}
                                    optional
                                >
                                    <Input
                                        id="prefix"
                                        name="prefix"
                                        defaultValue={currency?.prefix ?? ''}
                                        placeholder="$"
                                    />
                                </Field>
                                <Field
                                    htmlFor="suffix"
                                    label="Suffix"
                                    error={errors.suffix}
                                    optional
                                >
                                    <Input
                                        id="suffix"
                                        name="suffix"
                                        defaultValue={currency?.suffix ?? ''}
                                        placeholder={`e.g. ${currency?.code ?? 'USD'}`}
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
                                            currency?.decimals ?? decimals[0],
                                        )}
                                        disabled={decimals.length === 1}
                                        options={decimals.map((value) => ({
                                            value: String(value),
                                            label: String(value),
                                        }))}
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
                                        defaultValue={
                                            currency?.format ?? '1,234.56'
                                        }
                                        options={formats.map((value) => ({
                                            value,
                                            label: value,
                                        }))}
                                    />
                                </Field>
                                <Field
                                    htmlFor="exchange_rate"
                                    label={`Exchange rate (per 1 ${defaultCode})`}
                                    error={errors.exchange_rate}
                                    hint={
                                        currency?.is_default
                                            ? 'The default currency always has rate 1.'
                                            : 'Up to 6 decimal places.'
                                    }
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="exchange_rate"
                                        name="exchange_rate"
                                        inputMode="decimal"
                                        className="font-mono"
                                        defaultValue={
                                            currency
                                                ? String(
                                                      Number(
                                                          currency.exchange_rate,
                                                      ),
                                                  )
                                                : '1'
                                        }
                                        readOnly={currency?.is_default}
                                        aria-invalid={Boolean(
                                            errors.exchange_rate,
                                        )}
                                    />
                                </Field>
                            </div>
                        </SectionCard>
                    )}
                </Form>
            </Page>
        </>
    );
}

CurrencyForm.layout = {
    breadcrumbs: [{ title: 'Currencies', href: CurrencyController.index() }],
};
