import { Form, Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import StaffController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/StaffController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { SelectField } from '@/components/app/select-field';
import { SwitchField } from '@/components/app/switch-field';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

type RoleOption = {
    id: number;
    name: string;
    description: string | null;
    assignable: boolean;
};

type StaffRecord = {
    id: number;
    name: string;
    email: string;
    role_id: number | null;
    is_active: boolean;
    two_factor: boolean;
    last_login_at: string | null;
    last_login_ip: string | null;
    created_at: string | null;
    is_self: boolean;
};

type Props = { staff?: StaffRecord; roles: RoleOption[] };

export default function StaffForm({ staff, roles }: Props) {
    const [mode, setMode] = useState<'invite' | 'password'>('invite');
    const [roleId, setRoleId] = useState(
        staff?.role_id
            ? String(staff.role_id)
            : String(roles.find((role) => role.assignable)?.id ?? ''),
    );
    const selectedRole = roles.find((role) => String(role.id) === roleId);
    const action = staff
        ? StaffController.update.form(staff.id)
        : StaffController.store.form();
    const title = staff ? staff.name : 'Add staff';

    return (
        <>
            <Head title={staff ? `Edit ${staff.name}` : 'Add staff'} />
            <Page size="narrow">
                <PageHeader
                    back={{ href: StaffController.index(), label: 'Staff' }}
                    title={title}
                    status={
                        staff
                            ? staff.is_active
                                ? 'active'
                                : 'inactive'
                            : undefined
                    }
                    meta={staff ? <span>{staff.email}</span> : undefined}
                />

                <Form {...action} className="flex flex-col gap-6">
                    {({ errors, processing }) => (
                        <>
                            {!staff && (
                                <SectionCard title="How should they get access?">
                                    <input
                                        type="hidden"
                                        name="mode"
                                        value={mode}
                                    />
                                    <ToggleGroup
                                        type="single"
                                        variant="outline"
                                        value={mode}
                                        onValueChange={(value) =>
                                            value &&
                                            setMode(
                                                value as 'invite' | 'password',
                                            )
                                        }
                                        className="w-full sm:w-fit"
                                    >
                                        <ToggleGroupItem
                                            value="invite"
                                            className="px-4"
                                        >
                                            Send an invitation
                                        </ToggleGroupItem>
                                        <ToggleGroupItem
                                            value="password"
                                            className="px-4"
                                        >
                                            Set a password
                                        </ToggleGroupItem>
                                    </ToggleGroup>
                                    <p className="mt-3 text-sm text-muted-foreground">
                                        {mode === 'invite'
                                            ? 'They get an email link (valid for 7 days) to choose their own password.'
                                            : 'They must change this password the first time they sign in.'}
                                    </p>
                                </SectionCard>
                            )}

                            <SectionCard title="Details">
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field
                                        htmlFor="name"
                                        label="Name"
                                        error={errors.name}
                                        optional={!staff && mode === 'invite'}
                                    >
                                        <Input
                                            id="name"
                                            name="name"
                                            defaultValue={staff?.name ?? ''}
                                            autoComplete="off"
                                            aria-invalid={Boolean(errors.name)}
                                        />
                                    </Field>
                                    <Field
                                        htmlFor="email"
                                        label="Email"
                                        error={errors.email}
                                    >
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            defaultValue={staff?.email ?? ''}
                                            autoComplete="off"
                                            aria-invalid={Boolean(errors.email)}
                                        />
                                    </Field>
                                    {!staff && mode === 'password' && (
                                        <Field
                                            htmlFor="password"
                                            label="Temporary password"
                                            error={errors.password}
                                            hint="At least 12 characters with a letter and a number."
                                            className="sm:col-span-2"
                                        >
                                            <PasswordInput
                                                id="password"
                                                name="password"
                                                autoComplete="new-password"
                                                aria-invalid={Boolean(
                                                    errors.password,
                                                )}
                                            />
                                        </Field>
                                    )}
                                    <Field
                                        htmlFor="role_id"
                                        label="Role"
                                        error={errors.role_id}
                                        hint={
                                            selectedRole?.description ??
                                            'You can only assign roles whose permissions you hold.'
                                        }
                                        className="sm:col-span-2"
                                    >
                                        <SelectField
                                            id="role_id"
                                            name="role_id"
                                            value={roleId}
                                            onValueChange={setRoleId}
                                            placeholder="Choose a role"
                                            disabled={staff?.is_self}
                                            invalid={Boolean(errors.role_id)}
                                            options={roles.map((role) => ({
                                                value: String(role.id),
                                                label: role.name,
                                            }))}
                                        />
                                    </Field>
                                    {staff && (
                                        <div className="sm:col-span-2">
                                            <SwitchField
                                                id="is_active"
                                                name="is_active"
                                                label="Active"
                                                description={
                                                    staff.is_self
                                                        ? 'You cannot deactivate your own account.'
                                                        : 'Inactive staff are signed out immediately and cannot sign in.'
                                                }
                                                defaultChecked={staff.is_active}
                                                disabled={staff.is_self}
                                            />
                                            {errors.is_active && (
                                                <p className="mt-2 text-sm text-destructive">
                                                    {errors.is_active}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </SectionCard>

                            <FormActions>
                                <Button variant="outline" asChild>
                                    <Link href={StaffController.index()}>
                                        Cancel
                                    </Link>
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {staff
                                        ? 'Save changes'
                                        : mode === 'invite'
                                          ? 'Send invitation'
                                          : 'Create staff'}
                                </Button>
                            </FormActions>
                        </>
                    )}
                </Form>

                {staff && (
                    <SectionCard
                        title="Sign-in security"
                        footer={
                            staff.two_factor && !staff.is_self ? (
                                <ConfirmDialog
                                    title={`Reset two-factor authentication for ${staff.name}?`}
                                    description="They will have to set up 2FA again the next time they sign in."
                                    confirmLabel="Reset 2FA"
                                    method="post"
                                    action={StaffController.resetTwoFactor(
                                        staff.id,
                                    )}
                                    trigger={
                                        <Button variant="outline">
                                            Reset 2FA
                                        </Button>
                                    }
                                />
                            ) : undefined
                        }
                    >
                        <DescriptionList
                            items={[
                                {
                                    label: 'Two-factor authentication',
                                    value: staff.two_factor ? (
                                        <span className="inline-flex items-center gap-1">
                                            <ShieldCheck
                                                className="size-4 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                            Enabled
                                        </span>
                                    ) : (
                                        'Not set up'
                                    ),
                                },
                                {
                                    label: 'Last sign-in',
                                    value: (
                                        <DateTime
                                            value={staff.last_login_at}
                                            format="datetime"
                                            fallback="Never"
                                        />
                                    ),
                                },
                                {
                                    label: 'Last sign-in IP',
                                    value: staff.last_login_ip,
                                },
                                {
                                    label: 'Created',
                                    value: (
                                        <DateTime value={staff.created_at} />
                                    ),
                                },
                            ]}
                        />
                    </SectionCard>
                )}
            </Page>
        </>
    );
}

StaffForm.layout = {
    breadcrumbs: [{ title: 'Staff', href: StaffController.index() }],
};
