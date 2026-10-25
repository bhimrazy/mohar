import { Link, useForm, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import type { FormEvent } from 'react';
import { update } from '@/actions/App/Modules/Billing/Http/Controllers/Admin/NepalVatController';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Head } from '@/components/app/head';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { SwitchField } from '@/components/app/switch-field';
import { SelectField } from '@/components/billing/select-field';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { Brand } from '@/types/ui';

type Rule = {
    id: number;
    name: string;
    level: number;
    country: string | null;
    rate: number;
};

type Props = {
    mode: {
        enabled: boolean;
        enabled_from: string | null;
        vat_rules: number[];
        export_rule: number | null;
    };
    started: boolean;
    months: { value: string; label: string }[];
    rules: Rule[];
    blockers: string[];
    can_switch_off: boolean;
    tax_rules_url: string;
};

const NO_EXPORT = 'none';

function day(value: string | null): string {
    return value
        ? new Date(`${value}T00:00:00Z`).toLocaleDateString('en-GB', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              timeZone: 'UTC',
          })
        : '';
}

function describe(rule: Rule): string {
    return `${rule.rate}% · level ${rule.level} · ${rule.country ?? 'all countries'}`;
}

export default function VatModeEdit({
    mode,
    started,
    months,
    rules,
    blockers,
    can_switch_off,
    tax_rules_url,
}: Props) {
    const { brand } = usePage<{ brand: Brand }>().props;
    const form = useForm({
        enabled: mode.enabled,
        enabled_from: mode.enabled_from ?? months[0]?.value ?? '',
        vat_rules: mode.vat_rules,
        export_rule: mode.export_rule ? String(mode.export_rule) : NO_EXPORT,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const zeroRules = rules.filter((rule) => rule.rate === 0);
    const locked = mode.enabled && started;
    const mustStay = mode.enabled && !can_switch_off;

    const toggle = (id: number, checked: boolean) =>
        form.setData(
            'vat_rules',
            checked
                ? [...form.data.vat_rules, id]
                : form.data.vat_rules.filter((item) => item !== id),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            enabled_from: data.enabled ? data.enabled_from : null,
            export_rule:
                data.export_rule === NO_EXPORT
                    ? null
                    : Number(data.export_rule),
        }));
        form.submit(update());
    };

    return (
        <>
            <Head title="Nepal VAT" />
            <Page size="narrow">
                <PageHeader
                    title="Nepal VAT"
                    description="Keep the books the way the Inland Revenue Department expects: tax invoices numbered on payment, credit and debit notes for every correction, and a VAT sales register."
                    badges={
                        !mode.enabled ? (
                            <StatusBadge
                                status="off"
                                tone="neutral"
                                label="Off"
                            />
                        ) : started ? (
                            <StatusBadge
                                status="on"
                                tone="success"
                                label="On"
                            />
                        ) : (
                            <StatusBadge
                                status="scheduled"
                                tone="warning"
                                label={`Starts ${day(mode.enabled_from)}`}
                            />
                        )
                    }
                />

                <form onSubmit={submit} className="grid gap-6">
                    <SectionCard
                        title="Which tax rules are Nepal VAT"
                        description="Invoices can carry other taxes too. Only the rules ticked here count as VAT in the register. Rules are managed under Tax rules. Applies to invoices numbered after you save; issued invoices keep their classification."
                    >
                        {rules.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                There are no tax rules yet.{' '}
                                <Link
                                    href={tax_rules_url}
                                    className="underline"
                                >
                                    Add a 13% rule for Nepal
                                </Link>{' '}
                                first.
                            </p>
                        ) : (
                            <fieldset className="grid gap-3">
                                <legend className="sr-only">
                                    Tax rules that are Nepal VAT
                                </legend>
                                {rules.map((rule) => (
                                    <div
                                        key={rule.id}
                                        className="flex items-start gap-3"
                                    >
                                        <Checkbox
                                            id={`rule-${rule.id}`}
                                            checked={form.data.vat_rules.includes(
                                                rule.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                toggle(
                                                    rule.id,
                                                    checked === true,
                                                )
                                            }
                                            className="mt-0.5"
                                        />
                                        <Label
                                            htmlFor={`rule-${rule.id}`}
                                            className="grid gap-0.5 font-normal"
                                        >
                                            <span className="font-medium">
                                                {rule.name}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {describe(rule)}
                                            </span>
                                        </Label>
                                    </div>
                                ))}
                                <InputError message={errors.vat_rules} />
                            </fieldset>
                        )}
                    </SectionCard>

                    <SectionCard
                        title="Exports"
                        description="A sale counts as an export only when its invoice used the rule chosen here. Nothing is guessed from the client's country or currency."
                    >
                        <Field
                            htmlFor="export_rule"
                            label="Export tax rule"
                            hint="Pick a 0% rule that applies to clients outside Nepal. Choose none if you do not export."
                            error={errors.export_rule}
                        >
                            <SelectField
                                id="export_rule"
                                value={form.data.export_rule}
                                onChange={(value) =>
                                    form.setData('export_rule', value)
                                }
                                invalid={!!errors.export_rule}
                                options={[
                                    {
                                        value: NO_EXPORT,
                                        label: 'None, no sale is an export',
                                    },
                                    ...zeroRules.map((rule) => ({
                                        value: String(rule.id),
                                        label: `${rule.name} (${describe(rule)})`,
                                    })),
                                ]}
                            />
                        </Field>
                    </SectionCard>

                    <SectionCard
                        title="Switch on"
                        description={`When this is on, ${brand.name} numbers an invoice when it is paid (unpaid ones carry a proforma number), and every change to a numbered invoice is a credit or debit note.`}
                    >
                        <div className="grid gap-4">
                            {blockers.length > 0 && !mode.enabled && (
                                <Alert>
                                    <TriangleAlert />
                                    <AlertTitle>
                                        Not ready to switch on
                                    </AlertTitle>
                                    <AlertDescription>
                                        <ul className="list-disc pl-4">
                                            {blockers.map((blocker) => (
                                                <li key={blocker}>{blocker}</li>
                                            ))}
                                        </ul>
                                    </AlertDescription>
                                </Alert>
                            )}
                            <SwitchField
                                id="enabled"
                                label="Use Nepal VAT mode"
                                description={
                                    mustStay
                                        ? 'It cannot be switched off: credit or debit notes have been issued.'
                                        : 'Numbering formats and the pay-then-number setting are then fixed.'
                                }
                                checked={form.data.enabled}
                                disabled={mustStay}
                                onCheckedChange={(value) =>
                                    form.setData('enabled', value)
                                }
                            />
                            <InputError message={errors.enabled} />
                            {form.data.enabled && (
                                <Field
                                    htmlFor="enabled_from"
                                    label="Starts on"
                                    hint={
                                        locked
                                            ? 'The mode has started; its start day no longer changes.'
                                            : 'Always the first day of a Nepali (BS) month, so a VAT month is wholly before or wholly in the mode.'
                                    }
                                    error={errors.enabled_from}
                                >
                                    <SelectField
                                        id="enabled_from"
                                        value={form.data.enabled_from}
                                        onChange={(value) =>
                                            form.setData('enabled_from', value)
                                        }
                                        disabled={locked}
                                        invalid={!!errors.enabled_from}
                                        options={
                                            locked
                                                ? [
                                                      {
                                                          value: form.data
                                                              .enabled_from,
                                                          label: form.data
                                                              .enabled_from,
                                                      },
                                                  ]
                                                : months
                                        }
                                    />
                                </Field>
                            )}
                        </div>
                    </SectionCard>

                    <FormActions>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Save
                        </Button>
                    </FormActions>
                </form>
            </Page>
        </>
    );
}
