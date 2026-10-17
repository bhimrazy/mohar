import { Form, Head, Link } from '@inertiajs/react';
import ClientGroupController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/ClientGroupController';
import { Field } from '@/components/app/field';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { SwitchField } from '@/components/app/switch-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

type Group = {
    id: number;
    name: string;
    colour: string | null;
    discount_percent: string;
    exempt_from_suspension: boolean;
    separate_invoices: boolean;
};

export default function ClientGroupForm({ group }: { group?: Group }) {
    const action = group
        ? ClientGroupController.update.form(group.id)
        : ClientGroupController.store.form();

    return (
        <>
            <Head title={group ? `Edit ${group.name}` : 'New client group'} />
            <Page size="narrow">
                <PageHeader
                    back={{
                        href: ClientGroupController.index(),
                        label: 'Client groups',
                    }}
                    title={group ? group.name : 'New client group'}
                />
                <Form {...action}>
                    {({ errors, processing }) => (
                        <SectionCard
                            title="Group"
                            footer={
                                <>
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={ClientGroupController.index()}
                                        >
                                            Cancel
                                        </Link>
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {group ? 'Save group' : 'Create group'}
                                    </Button>
                                </>
                            }
                        >
                            <div className="grid gap-6 sm:grid-cols-2">
                                <Field
                                    htmlFor="name"
                                    label="Name"
                                    error={errors.name}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={group?.name ?? ''}
                                        aria-invalid={Boolean(errors.name)}
                                    />
                                </Field>
                                <Field
                                    htmlFor="discount_percent"
                                    label="Discount (%)"
                                    error={errors.discount_percent}
                                    hint="Applied to new and recurring invoice lines."
                                >
                                    <Input
                                        id="discount_percent"
                                        name="discount_percent"
                                        inputMode="decimal"
                                        defaultValue={
                                            group
                                                ? String(
                                                      Number(
                                                          group.discount_percent,
                                                      ),
                                                  )
                                                : '0'
                                        }
                                        aria-invalid={Boolean(
                                            errors.discount_percent,
                                        )}
                                    />
                                </Field>
                                <Field
                                    htmlFor="colour"
                                    label="Colour"
                                    error={errors.colour}
                                    optional
                                    hint="Hex value, e.g. #2563eb."
                                >
                                    <Input
                                        id="colour"
                                        name="colour"
                                        defaultValue={group?.colour ?? ''}
                                        placeholder="#2563eb"
                                        className="font-mono"
                                        aria-invalid={Boolean(errors.colour)}
                                    />
                                </Field>
                                <SwitchField
                                    id="exempt_from_suspension"
                                    name="exempt_from_suspension"
                                    label="Never auto-suspend"
                                    description="Overdue services of these clients are not suspended automatically."
                                    defaultChecked={
                                        group?.exempt_from_suspension ?? false
                                    }
                                    className="sm:col-span-2"
                                />
                                <SwitchField
                                    id="separate_invoices"
                                    name="separate_invoices"
                                    label="Separate invoices"
                                    description="Each service renews on its own invoice."
                                    defaultChecked={
                                        group?.separate_invoices ?? false
                                    }
                                    className="sm:col-span-2"
                                />
                            </div>
                        </SectionCard>
                    )}
                </Form>
            </Page>
        </>
    );
}

ClientGroupForm.layout = {
    breadcrumbs: [
        { title: 'Client groups', href: ClientGroupController.index() },
    ],
};
