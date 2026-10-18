import { useForm } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import type { FormEvent } from 'react';
import TldController from '@/actions/App/Modules/Domains/Http/Controllers/Admin/TldController';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { TldPricingGrid } from '@/components/domains/tld-pricing-grid';
import type { PriceRow } from '@/components/domains/tld-pricing-grid';
import { fromMinor, labelWithCurrency, parseMoneyInput } from '@/lib/money';

type Tld = {
    id: number;
    extension: string;
    registrar_key: string | null;
    auto_register: boolean;
    epp_required: boolean;
    min_years: number;
    max_years: number;
    allow_register: boolean;
    allow_transfer: boolean;
    grace_days: number;
    grace_fee: number;
    redemption_days: number;
    redemption_fee: number;
    renew_window_days: number | null;
    addon_dns: boolean;
    addon_id_protection: boolean;
    addon_email_forwarding: boolean;
    is_spotlight: boolean;
    badge: string | null;
    sort_order: number;
    is_active: boolean;
};

type Props = {
    tld: Tld | null;
    prices: PriceRow[];
    registrars: string[];
    registrarNames: Record<string, string>;
    currencies: string[];
    defaultCurrency: string;
    groups: { id: number; name: string }[];
    badges: string[];
    canManage: boolean;
};

const decimal = (amount: number | null) =>
    amount === null ? '' : fromMinor(amount).toFixed(2);

function SettingsForm({
    tld,
    registrars,
    registrarNames,
    badges,
    defaultCurrency,
    canManage,
}: Omit<Props, 'prices' | 'currencies' | 'groups'>) {
    const form = useForm({
        extension: tld?.extension ?? '',
        registrar_key: tld?.registrar_key ?? 'manual',
        auto_register: tld?.auto_register ?? true,
        epp_required: tld?.epp_required ?? true,
        min_years: String(tld?.min_years ?? 1),
        max_years: String(tld?.max_years ?? 10),
        allow_register: tld?.allow_register ?? true,
        allow_transfer: tld?.allow_transfer ?? true,
        grace_days: String(tld?.grace_days ?? 0),
        grace_fee: decimal(tld?.grace_fee ?? 0),
        redemption_days: String(tld?.redemption_days ?? 0),
        redemption_fee: decimal(tld?.redemption_fee ?? 0),
        renew_window_days: tld?.renew_window_days
            ? String(tld.renew_window_days)
            : '',
        addon_dns: tld?.addon_dns ?? false,
        addon_id_protection: tld?.addon_id_protection ?? false,
        addon_email_forwarding: tld?.addon_email_forwarding ?? false,
        is_spotlight: tld?.is_spotlight ?? false,
        badge: tld?.badge ?? 'none',
        sort_order: String(tld?.sort_order ?? 0),
        is_active: tld?.is_active ?? true,
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            registrar_key:
                data.registrar_key === 'manual' ? null : data.registrar_key,
            badge: data.badge === 'none' ? null : data.badge,
            min_years: Number(data.min_years),
            max_years: Number(data.max_years),
            grace_days: Number(data.grace_days),
            redemption_days: Number(data.redemption_days),
            grace_fee:
                data.grace_fee.trim() === ''
                    ? 0
                    : (parseMoneyInput(data.grace_fee) ?? data.grace_fee),
            redemption_fee:
                data.redemption_fee.trim() === ''
                    ? 0
                    : (parseMoneyInput(data.redemption_fee) ??
                      data.redemption_fee),
            renew_window_days: data.renew_window_days
                ? Number(data.renew_window_days)
                : null,
            sort_order: Number(data.sort_order),
        }));
        form.submit(
            tld ? TldController.update(tld.id) : TldController.store(),
            { preserveScroll: true },
        );
    };

    const text = (
        key:
            | 'extension'
            | 'min_years'
            | 'max_years'
            | 'grace_days'
            | 'grace_fee'
            | 'redemption_days'
            | 'redemption_fee'
            | 'renew_window_days'
            | 'sort_order',
        label: string,
        hint?: string,
        optional = false,
    ) => (
        <Field
            htmlFor={key}
            label={label}
            hint={hint}
            error={errors[key]}
            optional={optional}
        >
            <Input
                id={key}
                value={form.data[key]}
                disabled={!canManage}
                onChange={(event) => form.setData(key, event.target.value)}
                aria-invalid={!!errors[key]}
                aria-describedby={`${key}-description`}
            />
        </Field>
    );

    const toggles = [
        ['is_active', 'Visible and sellable'],
        ['allow_register', 'Allow registrations'],
        ['allow_transfer', 'Allow transfers in'],
        ['epp_required', 'Require EPP code for transfers'],
        ['auto_register', 'Submit to the registrar when paid'],
        ['is_spotlight', 'Spotlight in domain search'],
        ['addon_dns', 'Offer DNS management'],
        ['addon_id_protection', 'Offer ID protection'],
        ['addon_email_forwarding', 'Offer email forwarding'],
    ] as const;

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <SectionCard title="Extension">
                <div className="grid items-start gap-4 sm:grid-cols-2">
                    {text('extension', 'Extension', 'e.g. .com or .co.uk')}
                    <Field
                        htmlFor="registrar_key"
                        label="Registrar"
                        error={errors.registrar_key}
                    >
                        <Select
                            value={form.data.registrar_key}
                            disabled={!canManage}
                            onValueChange={(value) =>
                                form.setData('registrar_key', value)
                            }
                        >
                            <SelectTrigger
                                className="w-full"
                                id="registrar_key"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="manual">
                                    Manual (staff completes)
                                </SelectItem>
                                {registrars.map((registrar) => (
                                    <SelectItem
                                        key={registrar}
                                        value={registrar}
                                    >
                                        {registrarNames[registrar] ?? registrar}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    {text('min_years', 'Minimum years')}
                    {text('max_years', 'Maximum years')}
                    <Field htmlFor="badge" label="Badge" optional>
                        <Select
                            value={form.data.badge}
                            disabled={!canManage}
                            onValueChange={(value) =>
                                form.setData('badge', value)
                            }
                        >
                            <SelectTrigger className="w-full" id="badge">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">None</SelectItem>
                                {badges.map((badge) => (
                                    <SelectItem key={badge} value={badge}>
                                        {badge}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    {text('sort_order', 'Sort order')}
                </div>
            </SectionCard>

            <SectionCard
                title="Expiry"
                description={`Fees are charged in ${defaultCurrency} and converted at invoice time.`}
            >
                <div className="grid items-start gap-4 sm:grid-cols-2">
                    {text(
                        'grace_days',
                        'Grace period (days)',
                        '0 turns the grace period off.',
                    )}
                    {text(
                        'grace_fee',
                        labelWithCurrency('Grace fee', defaultCurrency),
                    )}
                    {text(
                        'redemption_days',
                        'Redemption period (days)',
                        'Starts when the grace period ends.',
                    )}
                    {text(
                        'redemption_fee',
                        labelWithCurrency('Redemption fee', defaultCurrency),
                    )}
                    {text(
                        'renew_window_days',
                        'Renewal window (days before expiry)',
                        'Hide renewal orders until this many days before expiry.',
                        true,
                    )}
                </div>
            </SectionCard>

            <SectionCard title="Options">
                <div className="grid gap-2 sm:grid-cols-2">
                    {toggles.map(([key, label]) => (
                        <label
                            key={key}
                            className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm font-medium"
                        >
                            {label}
                            <Switch
                                checked={form.data[key]}
                                disabled={!canManage}
                                onCheckedChange={(checked) =>
                                    form.setData(key, checked)
                                }
                            />
                        </label>
                    ))}
                </div>
            </SectionCard>

            {canManage && (
                <FormActions>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {tld ? 'Save TLD' : 'Create TLD'}
                    </Button>
                </FormActions>
            )}
        </form>
    );
}

export default function TldForm(props: Props) {
    const { tld } = props;

    return (
        <>
            <Head title={tld ? tld.extension : 'New TLD'} />
            <Page size="narrow">
                <PageHeader
                    back={{ href: TldController.index(), label: 'TLD pricing' }}
                    title={tld ? tld.extension : 'New TLD'}
                />
                <SettingsForm
                    tld={tld}
                    registrars={props.registrars}
                    registrarNames={props.registrarNames}
                    badges={props.badges}
                    defaultCurrency={props.defaultCurrency}
                    canManage={props.canManage}
                />
                {tld && (
                    <TldPricingGrid
                        key={`${tld.id}-${tld.min_years}-${tld.max_years}`}
                        tld={tld}
                        prices={props.prices}
                        currencies={props.currencies}
                        defaultCurrency={props.defaultCurrency}
                        groups={props.groups}
                        canManage={props.canManage}
                    />
                )}
            </Page>
        </>
    );
}
