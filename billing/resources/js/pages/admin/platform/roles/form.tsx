import { Link, useForm } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { Head } from '@/components/app/head';
import type { FormEvent } from 'react';
import RoleController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/RoleController';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { SwitchField } from '@/components/app/switch-field';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type PermissionOption = {
    key: string;
    label: string;
    grantable: boolean;
    /** Never granted by wildcards: must be ticked explicitly. */
    sensitive: boolean;
};
type PermissionGroup = {
    group: string;
    permissions: PermissionOption[];
    wildcards: { key: string; grantable: boolean }[];
};

type Props = {
    role?: {
        id: number;
        name: string;
        description: string | null;
        permissions: string[];
        is_system: boolean;
    };
    groups: PermissionGroup[];
    canGrantAll: boolean;
};

/** Whether a wildcard ("billing.*") covers a permission. */
function covers(wildcard: string, permission: string): boolean {
    return (
        wildcard === '*' ||
        (wildcard.endsWith('.*') &&
            permission.startsWith(wildcard.slice(0, -1)))
    );
}

export default function RoleForm({ role, groups, canGrantAll }: Props) {
    const form = useForm({
        name: role?.name ?? '',
        description: role?.description ?? '',
        permissions: role?.permissions ?? [],
    });
    const selected = form.data.permissions;
    const full = selected.includes('*');
    const wildcards = selected.filter((permission) => permission.endsWith('*'));
    const sensitiveKeys = new Set(
        groups.flatMap((group) =>
            group.permissions
                .filter((permission) => permission.sensitive)
                .map((permission) => permission.key),
        ),
    );
    const coveredBy = (key: string) =>
        sensitiveKeys.has(key)
            ? undefined
            : wildcards.find(
                  (wildcard) => wildcard !== key && covers(wildcard, key),
              );

    const toggle = (key: string, on: boolean) => {
        const next = on
            ? [...selected, key]
            : selected.filter((permission) => permission !== key);
        // Drop explicit grants a newly added wildcard already covers.
        form.setData(
            'permissions',
            on && key.endsWith('*')
                ? next.filter(
                      (permission) =>
                          permission === key || !covers(key, permission),
                  )
                : next,
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            role ? RoleController.update(role.id) : RoleController.store(),
        );
    };

    const permissionErrors = Object.entries(form.errors)
        .filter(([key]) => key.startsWith('permissions'))
        .map(([, message]) => message);

    return (
        <>
            <Head title={role ? `Edit ${role.name}` : 'New role'} />
            <Page size="narrow">
                <PageHeader
                    back={{ href: RoleController.index(), label: 'Roles' }}
                    title={role ? role.name : 'New role'}
                />

                {role?.is_system && (
                    <Alert>
                        <Info />
                        <AlertTitle>System role</AlertTitle>
                        <AlertDescription>
                            Every upgrade resets this role's permissions to the
                            built-in set; the name and description you give it
                            are kept. For different permissions, create a custom
                            role.
                        </AlertDescription>
                    </Alert>
                )}

                <form onSubmit={submit} className="flex flex-col gap-6">
                    <SectionCard title="Role">
                        <div className="grid gap-6">
                            <Field
                                htmlFor="name"
                                label="Name"
                                error={form.errors.name}
                            >
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    aria-invalid={Boolean(form.errors.name)}
                                />
                            </Field>
                            <Field
                                htmlFor="description"
                                label="Description"
                                error={form.errors.description}
                                optional
                            >
                                <Input
                                    id="description"
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Permissions"
                        description="You can only grant permissions your own role holds. View permissions cover lists and details; changes need their own permission."
                    >
                        <div className="flex flex-col gap-6">
                            {permissionErrors.map((message) => (
                                <InputError key={message} message={message} />
                            ))}

                            <SwitchField
                                id="perm-all"
                                label="Full access"
                                description={
                                    canGrantAll
                                        ? 'Every current and future permission (*).'
                                        : 'Only full administrators can grant full access.'
                                }
                                checked={full}
                                disabled={!canGrantAll}
                                onCheckedChange={(on) => toggle('*', on)}
                            />

                            {groups.map((group) => (
                                <fieldset
                                    key={group.group}
                                    className="space-y-3"
                                    disabled={full}
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2 border-b pb-2">
                                        <legend className="text-sm font-semibold">
                                            {group.group}
                                        </legend>
                                        <div className="flex flex-wrap gap-3">
                                            {group.wildcards.map((wildcard) => {
                                                const id = `perm-${wildcard.key}`;

                                                return (
                                                    <div
                                                        key={wildcard.key}
                                                        className="flex items-center gap-2"
                                                    >
                                                        <Checkbox
                                                            id={id}
                                                            checked={
                                                                full ||
                                                                selected.includes(
                                                                    wildcard.key,
                                                                ) ||
                                                                Boolean(
                                                                    coveredBy(
                                                                        wildcard.key,
                                                                    ),
                                                                )
                                                            }
                                                            disabled={
                                                                full ||
                                                                !wildcard.grantable ||
                                                                Boolean(
                                                                    coveredBy(
                                                                        wildcard.key,
                                                                    ),
                                                                )
                                                            }
                                                            onCheckedChange={(
                                                                checked,
                                                            ) =>
                                                                toggle(
                                                                    wildcard.key,
                                                                    checked ===
                                                                        true,
                                                                )
                                                            }
                                                        />
                                                        <Label
                                                            htmlFor={id}
                                                            className="font-mono text-xs font-normal"
                                                        >
                                                            {wildcard.key}
                                                        </Label>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {group.permissions.map((permission) => {
                                            const id = `perm-${permission.key}`;
                                            const covered =
                                                full ||
                                                Boolean(
                                                    coveredBy(permission.key),
                                                );

                                            return (
                                                <div
                                                    key={permission.key}
                                                    className="flex items-start gap-2"
                                                >
                                                    <Checkbox
                                                        id={id}
                                                        className="mt-0.5"
                                                        checked={
                                                            covered ||
                                                            selected.includes(
                                                                permission.key,
                                                            )
                                                        }
                                                        disabled={
                                                            covered ||
                                                            !permission.grantable
                                                        }
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            toggle(
                                                                permission.key,
                                                                checked ===
                                                                    true,
                                                            )
                                                        }
                                                    />
                                                    <Label
                                                        htmlFor={id}
                                                        className="flex flex-col items-start gap-0 font-normal"
                                                    >
                                                        <span className="flex items-center gap-1.5">
                                                            {permission.label}
                                                            {permission.sensitive && (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="px-1.5 py-0 text-[10px]"
                                                                    title="Never granted by a wildcard; tick it explicitly."
                                                                >
                                                                    Sensitive
                                                                </Badge>
                                                            )}
                                                        </span>
                                                        <span className="font-mono text-xs text-muted-foreground">
                                                            {permission.key}
                                                        </span>
                                                    </Label>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </fieldset>
                            ))}
                        </div>
                    </SectionCard>

                    <FormActions
                        sticky
                        status={`${full ? 'Full access' : `${selected.length} selected`}`}
                    >
                        <Button variant="outline" asChild>
                            <Link href={RoleController.index()}>Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {role ? 'Save role' : 'Create role'}
                        </Button>
                    </FormActions>
                </form>
            </Page>
        </>
    );
}

RoleForm.layout = {
    breadcrumbs: [{ title: 'Roles', href: RoleController.index() }],
};
