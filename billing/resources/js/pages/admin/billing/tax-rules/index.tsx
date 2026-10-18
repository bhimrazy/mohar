import { Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Percent, Plus } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { FormDialog } from '@/components/billing/form-dialog';
import { SelectField } from '@/components/billing/select-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    destroy,
    store,
    update,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/TaxRuleController';

type TaxRule = {
    id: number;
    name: string;
    level: number;
    country: string | null;
    state: string | null;
    scope: 'home_state' | 'other_state' | null;
    rate: number;
    priority: number;
    effective_from: string | null;
};

type Props = {
    rules: TaxRule[];
    settings_url: string | null;
    settings: {
        enabled: boolean;
        mode: string;
        compound: boolean;
        calculation: string;
        vat_enabled: boolean;
        vat_home_country: string;
        home_country: string;
        home_state: string;
    };
};

function RuleDialog({
    rule,
    open,
    onOpenChange,
}: {
    rule: TaxRule | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <FormDialog
            key={rule?.id ?? 'new'}
            open={open}
            onOpenChange={onOpenChange}
            title={rule ? `Edit ${rule.name}` : 'Add tax rule'}
            description="The most specific rule per level applies: country + state, then country, then all locations."
            submitLabel={rule ? 'Save rule' : 'Add rule'}
            action={rule ? update(rule.id) : store()}
            initial={{
                name: rule?.name ?? '',
                level: String(rule?.level ?? 1),
                country: rule?.country ?? '',
                state: rule?.state ?? '',
                scope: rule?.scope ?? 'any',
                rate: rule ? String(rule.rate) : '',
                priority: String(rule?.priority ?? 0),
                effective_from: rule?.effective_from ?? '',
            }}
            transform={(data) => ({
                ...data,
                level: Number(data.level),
                priority: Number(data.priority) || 0,
                effective_from: data.effective_from || null,
            })}
        >
            {(form) => (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        className="content-start"
                        htmlFor="rule-name"
                        label="Name"
                        error={form.errors.name}
                    >
                        <Input
                            id="rule-name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            placeholder="VAT"
                        />
                    </Field>
                    <Field
                        className="content-start"
                        htmlFor="rule-rate"
                        label="Rate (%)"
                        error={form.errors.rate}
                    >
                        <Input
                            id="rule-rate"
                            inputMode="decimal"
                            value={form.data.rate}
                            onChange={(event) =>
                                form.setData('rate', event.target.value)
                            }
                            placeholder="20"
                        />
                    </Field>
                    <Field
                        className="content-start"
                        htmlFor="rule-level"
                        label="Level"
                        error={form.errors.level}
                    >
                        <SelectField
                            id="rule-level"
                            value={form.data.level}
                            onChange={(value) => form.setData('level', value)}
                            options={[
                                { value: '1', label: 'Level 1' },
                                { value: '2', label: 'Level 2' },
                            ]}
                        />
                    </Field>
                    <Field
                        className="content-start"
                        htmlFor="rule-priority"
                        label="Priority"
                        optional
                        error={form.errors.priority}
                    >
                        <Input
                            id="rule-priority"
                            inputMode="numeric"
                            value={form.data.priority}
                            onChange={(event) =>
                                form.setData('priority', event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        className="content-start"
                        htmlFor="rule-country"
                        label="Country"
                        optional
                        hint="ISO code; empty = all"
                        error={form.errors.country}
                    >
                        <Input
                            id="rule-country"
                            maxLength={2}
                            value={form.data.country}
                            onChange={(event) =>
                                form.setData(
                                    'country',
                                    event.target.value.toUpperCase(),
                                )
                            }
                        />
                    </Field>
                    <Field
                        className="content-start"
                        htmlFor="rule-state"
                        label="State"
                        optional
                        error={form.errors.state}
                    >
                        <Input
                            id="rule-state"
                            value={form.data.state}
                            onChange={(event) =>
                                form.setData('state', event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        htmlFor="rule-scope"
                        label="Supply"
                        hint="India GST: CGST + SGST within your state, IGST across states"
                        error={form.errors.scope}
                        className="content-start sm:col-span-2"
                    >
                        <SelectField
                            id="rule-scope"
                            value={form.data.scope}
                            onChange={(value) => form.setData('scope', value)}
                            options={[
                                { value: 'any', label: 'Any location' },
                                {
                                    value: 'home_state',
                                    label: 'Within the seller’s state (intra-state)',
                                },
                                {
                                    value: 'other_state',
                                    label: 'Other states (inter-state)',
                                },
                            ]}
                        />
                    </Field>
                    <Field
                        htmlFor="rule-effective"
                        label="Effective from"
                        optional
                        hint="Schedule a rate change"
                        error={form.errors.effective_from}
                        className="content-start sm:col-span-2"
                    >
                        <Input
                            id="rule-effective"
                            type="date"
                            value={form.data.effective_from}
                            onChange={(event) =>
                                form.setData(
                                    'effective_from',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                </div>
            )}
        </FormDialog>
    );
}

export default function TaxRulesIndex({
    rules,
    settings,
    settings_url,
}: Props) {
    const [editing, setEditing] = useState<TaxRule | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<TaxRule | null>(null);

    const columns: DataTableColumn<TaxRule>[] = [
        {
            key: 'name',
            header: 'Name',
            primary: true,
            cell: (rule) => rule.name,
        },
        {
            key: 'level',
            header: 'Level',
            cell: (rule) => `Level ${rule.level}`,
        },
        {
            key: 'location',
            header: 'Applies to',
            cell: (rule) =>
                rule.country
                    ? [
                          rule.country,
                          rule.state,
                          rule.scope === 'home_state'
                              ? 'Intra-state'
                              : rule.scope === 'other_state'
                                ? 'Inter-state'
                                : null,
                      ]
                          .filter(Boolean)
                          .join(' · ')
                    : 'All locations',
        },
        {
            key: 'rate',
            header: 'Rate',
            align: 'right',
            cell: (rule) => <span className="tabular-nums">{rule.rate}%</span>,
        },
        {
            key: 'effective',
            header: 'Effective from',
            hideOnMobile: true,
            cell: (rule) => (
                <DateTime value={rule.effective_from} fallback="Always" />
            ),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            align: 'right',
            hideOnMobile: true,
            cell: (rule) => (
                <div className="flex justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setEditing(rule);
                            setOpen(true);
                        }}
                    >
                        Edit
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setDeleting(rule)}
                    >
                        Delete
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="Tax rules" />
            <Page>
                <PageHeader
                    title="Tax rules"
                    description="Issued invoices keep the tax they were issued with; changing a rule only affects new invoices."
                    actions={
                        <Button
                            onClick={() => {
                                setEditing(null);
                                setOpen(true);
                            }}
                        >
                            <Plus />
                            Add tax rule
                        </Button>
                    }
                />
                <SectionCard
                    title="Tax settings"
                    description={
                        settings_url ? (
                            <>
                                Change these in{' '}
                                <Link
                                    href={settings_url}
                                    className="underline underline-offset-4 hover:text-foreground"
                                >
                                    Settings → Billing
                                </Link>
                                .
                            </>
                        ) : (
                            'Managed in the platform settings (Billing section).'
                        )
                    }
                >
                    <DescriptionList
                        columns={3}
                        items={[
                            {
                                label: 'Tax',
                                value: settings.enabled
                                    ? 'Enabled'
                                    : 'Disabled',
                            },
                            {
                                label: 'Prices are',
                                value:
                                    settings.mode === 'inclusive'
                                        ? 'Inclusive of tax'
                                        : 'Exclusive of tax',
                            },
                            {
                                label: 'Level 2',
                                value: settings.compound
                                    ? 'Compound (on net + level 1)'
                                    : 'On net amount',
                            },
                            {
                                label: 'Calculation',
                                value:
                                    settings.calculation === 'per_invoice'
                                        ? 'Per invoice'
                                        : 'Per line',
                            },
                            {
                                label: 'Seller location',
                                value: [
                                    settings.home_country,
                                    settings.home_state,
                                ]
                                    .filter(Boolean)
                                    .join(' · '),
                            },
                            {
                                label: 'EU VAT rules',
                                value: settings.vat_enabled
                                    ? `Enabled (home ${settings.vat_home_country})`
                                    : 'Disabled',
                            },
                        ]}
                    />
                </SectionCard>
                <DataTable
                    caption="Tax rules"
                    columns={columns}
                    rows={rules}
                    getRowKey={(rule) => rule.id}
                    empty={
                        <EmptyState
                            icon={Percent}
                            title="No tax rules yet"
                            description="Add a rule per country or state to charge tax on invoices."
                        />
                    }
                />
            </Page>
            <RuleDialog rule={editing} open={open} onOpenChange={setOpen} />
            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(next) => !next && setDeleting(null)}
                title={`Delete ${deleting?.name ?? 'tax rule'}?`}
                description="New invoices stop using this rule. Issued invoices are not affected."
                confirmLabel="Delete rule"
                action={deleting ? destroy(deleting.id) : undefined}
            />
        </>
    );
}
