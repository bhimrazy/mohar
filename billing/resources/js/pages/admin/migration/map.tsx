import { Link, router, setLayoutProps, usePage } from '@inertiajs/react';
import { CircleAlert, Lock } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { Field } from '@/components/app/field';
import { Head } from '@/components/app/head';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { SelectField } from '@/components/app/select-field';
import { SwitchField } from '@/components/app/switch-field';
import {
    count,
    ImportMeta,
    ImportProgress,
    ImportSteps,
} from '@/components/migration/import-status';
import type { ImportRun } from '@/components/migration/import-status';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import ClientImportController from '@/actions/App/Modules/Migration/Http/Controllers/Admin/ClientImportController';
import ClientController from '@/actions/App/Modules/Core/Http/Controllers/Admin/ClientController';
import type { SelectOption } from '@/types/app';

type Column = { header: string; samples: string[]; forbidden: boolean };
type Found = { value: string; count: number };
type Options = {
    on_match: string;
    blank_cells: string;
    closed_clients: string;
    match_company: boolean;
    match_tax_id: boolean;
    create_logins: boolean;
    trust_verified: boolean;
    groups?: Record<string, string>;
    currencies?: Record<string, string>;
};

type Props = {
    run: ImportRun;
    columns: Column[];
    mapping: { columns: (string | null)[]; date_format: string | null };
    options: Options;
    suggestions: Record<string, (string | null)[]>;
    targets: SelectOption[];
    required: string[];
    dateTargets: string[];
    dateFormats: SelectOption[];
    presets: SelectOption[];
    found: { groups?: Found[]; currencies?: Found[]; countries?: Found[] };
    groupOptions: { id: number; name: string }[];
    currencyOptions: string[];
    earlier: {
        name: string;
        uploaded_at: string | null;
        by: string | null;
        href: string;
    } | null;
};

const CHOICES: {
    key: 'on_match' | 'blank_cells' | 'closed_clients';
    label: string;
    options: SelectOption[];
}[] = [
    {
        key: 'on_match',
        label: 'When an email matches a client',
        options: [
            { value: 'skip', label: 'Skip the row' },
            { value: 'update', label: 'Update the client' },
        ],
    },
    {
        key: 'blank_cells',
        label: 'Blank cells on update',
        options: [
            { value: 'keep', label: 'Keep the current value' },
            { value: 'clear', label: 'Clear the value' },
        ],
    },
    {
        key: 'closed_clients',
        label: 'Closed clients in the file',
        options: [
            { value: 'import', label: 'Import as closed, without a login' },
            { value: 'skip', label: 'Skip them' },
        ],
    },
];

const SWITCHES: { key: keyof Options; label: string; description: string }[] = [
    {
        key: 'match_company',
        label: 'Flag clients with the same company name',
        description:
            'Compared without case, punctuation or endings like Pvt Ltd, LLC or GmbH.',
    },
    {
        key: 'match_tax_id',
        label: 'Flag clients with the same tax ID',
        description: 'Each match needs your decision.',
    },
    {
        key: 'create_logins',
        label: 'Create a login for each new client',
        description:
            'Without a password. Invitations are sent separately, when you choose.',
    },
    {
        key: 'trust_verified',
        label: "Trust the file's email verified column",
        description:
            'Off: every imported login verifies its address from the invitation.',
    },
];

export default function ImportMap({
    run,
    columns,
    mapping,
    options: initialOptions,
    suggestions,
    targets,
    required,
    dateTargets,
    dateFormats,
    presets,
    found,
    groupOptions,
    currencyOptions,
    earlier,
}: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [chosen, setChosen] = useState(mapping.columns);
    const [dateFormat, setDateFormat] = useState(mapping.date_format ?? '');
    const [options, setOptions] = useState(initialOptions);
    const [groups, setGroups] = useState(initialOptions.groups ?? {});
    const [currencies, setCurrencies] = useState(
        initialOptions.currencies ?? {},
    );
    const [saving, setSaving] = useState(false);
    const staged = !['uploaded', 'staging'].includes(run.status);
    const missing = required.filter((target) => !chosen.includes(target));
    const unchosen = chosen.filter((target) => target === null).length;
    const needsDate = chosen.some((target) =>
        dateTargets.includes(target ?? ''),
    );
    const label = (value: string) =>
        targets.find((target) => target.value === value)?.label.toLowerCase();
    const blocked =
        missing.length > 0
            ? `Map ${missing.map(label).join(', ')} first.`
            : unchosen > 0
              ? `Choose a target for ${unchosen === 1 ? 'the column' : `the ${unchosen} columns`} without one.`
              : needsDate && !dateFormat
                ? 'Choose how the dates are written.'
                : null;

    setLayoutProps({
        breadcrumbs: [
            { title: 'Clients', href: ClientController.index() },
            { title: 'Import clients', href: ClientImportController.index() },
            { title: run.name, href: ClientImportController.show(run.id) },
        ],
    });

    const save = () =>
        router.put(
            ClientImportController.mapping(run.id).url,
            {
                columns: chosen,
                date_format: needsDate ? dateFormat : null,
                options: { ...options, groups, currencies },
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
            },
        );

    const tableColumns: DataTableColumn<Column & { index: number }>[] = [
        {
            key: 'column',
            header: 'Column in the file',
            primary: true,
            cell: (column) => (
                <span className="font-medium break-all">
                    {column.header || `Column ${column.index + 1} (no name)`}
                </span>
            ),
        },
        {
            key: 'samples',
            header: 'First values',
            cell: (column) =>
                column.forbidden ? (
                    <span className="text-xs text-muted-foreground">
                        Not stored
                    </span>
                ) : (
                    <span className="flex max-w-xs flex-col text-xs break-all whitespace-normal text-muted-foreground">
                        {column.samples.length > 0
                            ? column.samples.map((sample, index) => (
                                  <span key={index}>{sample}</span>
                              ))
                            : 'Empty in the first rows'}
                    </span>
                ),
        },
        {
            key: 'target',
            header: 'Becomes',
            cell: (column) =>
                column.forbidden ? (
                    <span className="flex max-w-xs items-start gap-2 text-sm whitespace-normal text-muted-foreground">
                        <Lock className="mt-0.5 size-4 shrink-0" />
                        Ignored: passwords and card data cannot be imported.
                        Clients set a password from their invitation.
                    </span>
                ) : (
                    <SelectField
                        id={`column-${column.index}`}
                        className="w-full sm:w-64"
                        placeholder="Choose a target"
                        options={targets}
                        invalid={!!errors[`columns.${column.index}`]}
                        value={chosen[column.index] ?? ''}
                        onValueChange={(value) =>
                            setChosen(
                                chosen.map((target, index) =>
                                    index === column.index ? value : target,
                                ),
                            )
                        }
                    />
                ),
        },
    ];

    const errorList = Object.entries(errors).filter(
        ([key]) => key === 'columns' || key === 'groups' || key === 'mapping',
    );

    return (
        <>
            <Head title={`Map columns · ${run.name}`} />
            <Page>
                <PageHeader
                    back={{
                        href: ClientImportController.index(),
                        label: 'Import clients',
                    }}
                    title="Map columns"
                    meta={<ImportMeta run={run} />}
                    description="Choose what each column of the file becomes. Suggestions come from the column names."
                />
                <ImportSteps current={1} />
                <ImportProgress run={run} />

                {earlier && (
                    <Alert>
                        <CircleAlert />
                        <AlertTitle>
                            This file was uploaded to this source before
                        </AlertTitle>
                        <AlertDescription>
                            <span>
                                <Link href={earlier.href} className="underline">
                                    {earlier.name}
                                </Link>{' '}
                                was uploaded{' '}
                                <DateTime
                                    value={earlier.uploaded_at}
                                    format="datetime"
                                />
                                {earlier.by && ` by ${earlier.by}`}. You can
                                continue: rows imported before come out
                                unchanged.
                            </span>
                        </AlertDescription>
                    </Alert>
                )}

                {run.status === 'mapping' &&
                    (found.groups ?? []).length > 0 && (
                        <Alert variant="destructive">
                            <CircleAlert />
                            <AlertTitle>
                                The check needs your decision
                            </AlertTitle>
                            <AlertDescription>
                                Some client groups in the file do not exist
                                here. Choose what to do with each one below,
                                then check again.
                            </AlertDescription>
                        </Alert>
                    )}

                {errorList.length > 0 && (
                    <Alert variant="destructive">
                        <CircleAlert />
                        <AlertTitle>The rows cannot be checked yet</AlertTitle>
                        <AlertDescription>
                            <ul className="list-disc pl-4">
                                {errorList.map(([key, message]) => (
                                    <li key={key}>{message}</li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                {staged && (
                    <>
                        <SectionCard
                            title={`Columns (${columns.length})`}
                            description="Unrecognised columns are kept as imported fields on the client profile; choose Ignore to drop them."
                            actions={
                                <SelectField
                                    id="preset"
                                    className="w-full sm:w-56"
                                    placeholder="Suggest again from…"
                                    options={presets}
                                    onValueChange={(preset) =>
                                        setChosen(suggestions[preset] ?? chosen)
                                    }
                                />
                            }
                            flush
                        >
                            <DataTable
                                caption="Columns in the file"
                                columns={tableColumns}
                                rows={columns.map((column, index) => ({
                                    ...column,
                                    index,
                                }))}
                                getRowKey={(column) => column.index}
                            />
                        </SectionCard>

                        {needsDate && (
                            <SectionCard title="Dates">
                                <Field
                                    htmlFor="date_format"
                                    label="How the dates are written"
                                    hint="03/04/2024 can be 3 April or 4 March, so choose the order the file uses."
                                    error={errors.date_format}
                                    className="max-w-sm"
                                >
                                    <SelectField
                                        id="date_format"
                                        options={dateFormats}
                                        placeholder="Choose a format"
                                        value={dateFormat}
                                        onValueChange={setDateFormat}
                                    />
                                </Field>
                            </SectionCard>
                        )}

                        <ValueDecisions
                            found={found}
                            groupOptions={groupOptions}
                            currencyOptions={currencyOptions}
                            groups={groups}
                            setGroups={setGroups}
                            currencies={currencies}
                            setCurrencies={setCurrencies}
                        />

                        <SectionCard
                            title="Options"
                            footer={
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                    <Button
                                        onClick={save}
                                        disabled={saving || blocked !== null}
                                    >
                                        {saving && <Spinner />}
                                        Save and check {count(run.rows)} rows
                                    </Button>
                                    {blocked && (
                                        <span className="text-sm text-muted-foreground">
                                            {blocked}
                                        </span>
                                    )}
                                </div>
                            }
                        >
                            <div className="grid gap-6 sm:grid-cols-3">
                                {CHOICES.map((choice) => (
                                    <Field
                                        key={choice.key}
                                        htmlFor={choice.key}
                                        label={choice.label}
                                    >
                                        <SelectField
                                            id={choice.key}
                                            options={choice.options}
                                            value={options[choice.key]}
                                            onValueChange={(value) =>
                                                setOptions({
                                                    ...options,
                                                    [choice.key]: value,
                                                })
                                            }
                                        />
                                    </Field>
                                ))}
                            </div>
                            <div className="mt-6 grid gap-3 sm:grid-cols-2">
                                {SWITCHES.map((item) => (
                                    <SwitchField
                                        key={item.key}
                                        id={item.key}
                                        label={item.label}
                                        description={item.description}
                                        checked={Boolean(options[item.key])}
                                        onCheckedChange={(checked) =>
                                            setOptions({
                                                ...options,
                                                [item.key]: checked,
                                            })
                                        }
                                    />
                                ))}
                            </div>
                        </SectionCard>
                    </>
                )}
            </Page>
        </>
    );
}

/** Group names, currencies and countries the last check could not place, with what to do about each. */
function ValueDecisions({
    found,
    groupOptions,
    currencyOptions,
    groups,
    setGroups,
    currencies,
    setCurrencies,
}: {
    found: Props['found'];
    groupOptions: Props['groupOptions'];
    currencyOptions: string[];
    groups: Record<string, string>;
    setGroups: (groups: Record<string, string>) => void;
    currencies: Record<string, string>;
    setCurrencies: (currencies: Record<string, string>) => void;
}) {
    const groupChoices: SelectOption[] = [
        ...groupOptions.map((group) => ({
            value: String(group.id),
            label: `Use ${group.name}`,
        })),
        { value: 'create', label: 'Create this group' },
        { value: 'none', label: 'No group' },
    ];
    const currencyChoices: SelectOption[] = [
        ...currencyOptions.map((code) => ({
            value: code,
            label: `Use ${code}`,
        })),
        { value: 'error', label: 'Leave these rows as errors' },
    ];
    const rows = (
        prefix: string,
        items: Found[] | undefined,
        choices: SelectOption[] | null,
        value: Record<string, string>,
        onChange: (value: Record<string, string>) => void,
    ) =>
        (items ?? []).map((item, index) => (
            <div
                key={item.value}
                className="flex flex-col gap-2 border-b py-3 last:border-0 sm:flex-row sm:items-center sm:justify-between"
            >
                <span className="text-sm">
                    <span className="font-medium break-all">{item.value}</span>{' '}
                    <span className="text-muted-foreground">
                        · {count(item.count)}{' '}
                        {item.count === 1 ? 'row' : 'rows'}
                    </span>
                </span>
                {choices && (
                    <SelectField
                        id={`${prefix}-${index}`}
                        className="w-full sm:w-64"
                        placeholder="Choose"
                        options={choices}
                        value={value[item.value] ?? ''}
                        onValueChange={(choice) =>
                            onChange({ ...value, [item.value]: choice })
                        }
                    />
                )}
            </div>
        ));

    return (
        <>
            {(found.groups ?? []).length > 0 && (
                <SectionCard
                    title="Client groups not found"
                    description="Map each one to a group, create it (name only, no discount), or import the rows without a group."
                >
                    {rows(
                        'group',
                        found.groups,
                        groupChoices,
                        groups,
                        setGroups,
                    )}
                </SectionCard>
            )}
            {(found.currencies ?? []).length > 0 && (
                <SectionCard
                    title="Currencies not enabled"
                    description="Map each one to an enabled currency, or leave its rows as errors."
                >
                    {rows(
                        'currency',
                        found.currencies,
                        currencyChoices,
                        currencies,
                        setCurrencies,
                    )}
                </SectionCard>
            )}
            {(found.countries ?? []).length > 0 && (
                <SectionCard
                    title="Countries not recognised"
                    description="These rows are errors. Use two-letter country codes such as IN, NP or US in the file and upload it again."
                >
                    {rows(
                        'country',
                        found.countries,
                        null,
                        {},
                        () => undefined,
                    )}
                </SectionCard>
            )}
        </>
    );
}
