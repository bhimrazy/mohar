import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { fromMinor, toMinor } from '@/lib/money';

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

type PriceRow = {
    id: number;
    currency: string;
    client_group_id: number | null;
    years: number;
    register: number | null;
    transfer: number | null;
    renew: number | null;
};

type Props = {
    tld: Tld | null;
    prices: PriceRow[];
    registrars: string[];
    currencies: string[];
    defaultCurrency: string;
    groups: { id: number; name: string }[];
    badges: string[];
    canManage: boolean;
};

const BASE = 'base';
const ACTIONS = ['register', 'transfer', 'renew'] as const;
type GridRow = {
    years: number;
    register: string;
    transfer: string;
    renew: string;
};

const decimal = (amount: number | null) =>
    amount === null ? '' : fromMinor(amount).toFixed(2);
const minor = (value: string) => (value.trim() === '' ? null : toMinor(value));

function SettingsForm({
    tld,
    registrars,
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
            grace_fee: toMinor(data.grace_fee || '0'),
            redemption_fee: toMinor(data.redemption_fee || '0'),
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
                <div className="grid gap-4 sm:grid-cols-2">
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
                            <SelectTrigger id="registrar_key">
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
                                        {registrar}
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
                            <SelectTrigger id="badge">
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
                <div className="grid gap-4 sm:grid-cols-2">
                    {text(
                        'grace_days',
                        'Grace period (days)',
                        '0 turns the grace period off.',
                    )}
                    {text('grace_fee', `Grace fee (${defaultCurrency})`)}
                    {text(
                        'redemption_days',
                        'Redemption period (days)',
                        'Starts when the grace period ends.',
                    )}
                    {text(
                        'redemption_fee',
                        `Redemption fee (${defaultCurrency})`,
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

function PricingGrid({
    tld,
    prices,
    currencies,
    groups,
    canManage,
}: { tld: Tld } & Pick<
    Props,
    'prices' | 'currencies' | 'groups' | 'canManage'
>) {
    const [currency, setCurrency] = useState(currencies[0] ?? 'USD');
    const [group, setGroup] = useState<string>(BASE);
    const groupId = group === BASE ? null : Number(group);

    const rowsFor = (
        selectedCurrency: string,
        selectedGroup: number | null,
    ): GridRow[] =>
        Array.from(
            { length: tld.max_years - tld.min_years + 1 },
            (_, index) => {
                const years = tld.min_years + index;
                const existing = prices.find(
                    (price) =>
                        price.currency === selectedCurrency &&
                        price.client_group_id === selectedGroup &&
                        price.years === years,
                );

                return {
                    years,
                    register: decimal(existing?.register ?? null),
                    transfer: decimal(existing?.transfer ?? null),
                    renew: decimal(existing?.renew ?? null),
                };
            },
        );

    const form = useForm({ rows: rowsFor(currency, groupId) });

    const select = (nextCurrency: string, nextGroup: string) => {
        setCurrency(nextCurrency);
        setGroup(nextGroup);
        const rows = rowsFor(
            nextCurrency,
            nextGroup === BASE ? null : Number(nextGroup),
        );
        form.setDefaults({ rows });
        form.setData('rows', rows);
        form.clearErrors();
    };

    const setCell = (
        index: number,
        action: (typeof ACTIONS)[number],
        value: string,
    ) =>
        form.setData(
            'rows',
            form.data.rows.map((row, i) =>
                i === index ? { ...row, [action]: value } : row,
            ),
        );

    const fillFromFirstYear = () => {
        const first = form.data.rows[0];
        form.setData(
            'rows',
            form.data.rows.map((row, index) =>
                index === 0
                    ? row
                    : {
                          years: row.years,
                          register: first.register
                              ? (
                                    (Number(first.register) * row.years) /
                                    first.years
                                ).toFixed(2)
                              : '',
                          transfer: '',
                          renew: first.renew
                              ? (
                                    (Number(first.renew) * row.years) /
                                    first.years
                                ).toFixed(2)
                              : '',
                      },
            ),
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            currency,
            client_group_id: groupId,
            prices: data.rows.map((row) => ({
                years: row.years,
                register: minor(row.register),
                transfer: minor(row.transfer),
                renew: minor(row.renew),
            })),
        }));
        form.submit(TldController.prices(tld.id), { preserveScroll: true });
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit}>
            <SectionCard
                title="Prices"
                description="Leave a cell empty when the term isn't offered. Client-group slabs override the base price; transfers sell the lowest term only."
                flush
                footer={
                    canManage && (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={fillFromFirstYear}
                            >
                                Fill years from 1-year price
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing || !form.isDirty}
                            >
                                {form.processing && <Spinner />}
                                Save prices
                            </Button>
                        </>
                    )
                }
            >
                <div className="flex flex-col gap-3 border-b px-4 py-3 sm:flex-row sm:px-6">
                    <Select
                        value={currency}
                        onValueChange={(value) => select(value, group)}
                    >
                        <SelectTrigger
                            className="sm:w-40"
                            aria-label="Currency"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {currencies.map((code) => (
                                <SelectItem key={code} value={code}>
                                    {code}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={group}
                        onValueChange={(value) => select(currency, value)}
                    >
                        <SelectTrigger
                            className="sm:w-56"
                            aria-label="Client group"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={BASE}>
                                Base price (all clients)
                            </SelectItem>
                            {groups.map((item) => (
                                <SelectItem
                                    key={item.id}
                                    value={String(item.id)}
                                >
                                    {item.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader className="bg-muted/40">
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="h-9 pl-4 text-xs sm:pl-6">
                                    Term
                                </TableHead>
                                {ACTIONS.map((action) => (
                                    <TableHead
                                        key={action}
                                        className="h-9 text-right text-xs capitalize"
                                    >
                                        {action} ({currency})
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {form.data.rows.map((row, index) => (
                                <TableRow key={row.years}>
                                    <TableCell className="pl-4 font-medium sm:pl-6">
                                        {row.years} year
                                        {row.years === 1 ? '' : 's'}
                                    </TableCell>
                                    {ACTIONS.map((action) => {
                                        const error =
                                            errors[`prices.${index}.${action}`];

                                        return (
                                            <TableCell
                                                key={action}
                                                className="py-1.5 last:pr-4 sm:last:pr-6"
                                            >
                                                <Input
                                                    aria-label={`${action} price for ${row.years} year${row.years === 1 ? '' : 's'}`}
                                                    inputMode="decimal"
                                                    className="ml-auto w-28 text-right tabular-nums"
                                                    value={row[action]}
                                                    disabled={!canManage}
                                                    aria-invalid={!!error}
                                                    placeholder="—"
                                                    onChange={(event) =>
                                                        setCell(
                                                            index,
                                                            action,
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                        );
                                    })}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </SectionCard>
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
                    badges={props.badges}
                    defaultCurrency={props.defaultCurrency}
                    canManage={props.canManage}
                />
                {tld && (
                    <PricingGrid
                        key={`${tld.id}-${tld.min_years}-${tld.max_years}`}
                        tld={tld}
                        prices={props.prices}
                        currencies={props.currencies}
                        groups={props.groups}
                        canManage={props.canManage}
                    />
                )}
            </Page>
        </>
    );
}
