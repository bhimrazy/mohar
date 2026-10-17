import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ProductGroupController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ProductGroupController';
import { SwitchRow } from '@/components/catalog/switch-row';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

type Group = {
    id: number;
    name: string;
    slug: string;
    headline: string | null;
    tagline: string | null;
    description: string | null;
    is_hidden: boolean;
    allowed_gateways: string[] | null;
    order_form_template: string | null;
};

export default function ProductGroupForm({ group }: { group: Group | null }) {
    const form = useForm({
        name: group?.name ?? '',
        slug: group?.slug ?? '',
        headline: group?.headline ?? '',
        tagline: group?.tagline ?? '',
        description: group?.description ?? '',
        is_hidden: group?.is_hidden ?? false,
        restrict_gateways: group?.allowed_gateways != null,
        allowed_gateways: (group?.allowed_gateways ?? []).join(', '),
    });

    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(
            group
                ? ProductGroupController.update(group)
                : ProductGroupController.store(),
        );
    };

    const title = group ? `Edit ${group.name}` : 'New product group';

    return (
        <>
            <Head title={title} />
            <Page size="narrow">
                <PageHeader
                    back={{
                        href: ProductGroupController.index(),
                        label: 'Product groups',
                    }}
                    title={title}
                />
                <form onSubmit={submit} className="flex flex-col gap-6">
                    <SectionCard title="Group">
                        <div className="grid gap-6 sm:grid-cols-2">
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
                                    aria-invalid={!!form.errors.name}
                                    aria-describedby="name-description"
                                    required
                                />
                            </Field>
                            <Field
                                htmlFor="slug"
                                label="Slug"
                                optional
                                hint="Used in the store link. Generated from the name when empty."
                                error={form.errors.slug}
                            >
                                <Input
                                    id="slug"
                                    value={form.data.slug}
                                    onChange={(event) =>
                                        form.setData('slug', event.target.value)
                                    }
                                    aria-invalid={!!form.errors.slug}
                                    aria-describedby="slug-description"
                                    className="font-mono"
                                />
                            </Field>
                            <Field
                                htmlFor="headline"
                                label="Headline"
                                optional
                                error={form.errors.headline}
                                className="sm:col-span-2"
                            >
                                <Input
                                    id="headline"
                                    value={form.data.headline}
                                    onChange={(event) =>
                                        form.setData(
                                            'headline',
                                            event.target.value,
                                        )
                                    }
                                    aria-describedby="headline-description"
                                />
                            </Field>
                            <Field
                                htmlFor="tagline"
                                label="Tagline"
                                optional
                                error={form.errors.tagline}
                                className="sm:col-span-2"
                            >
                                <Input
                                    id="tagline"
                                    value={form.data.tagline}
                                    onChange={(event) =>
                                        form.setData(
                                            'tagline',
                                            event.target.value,
                                        )
                                    }
                                    aria-describedby="tagline-description"
                                />
                            </Field>
                            <Field
                                htmlFor="description"
                                label="Description"
                                optional
                                error={form.errors.description}
                                className="sm:col-span-2"
                            >
                                <Textarea
                                    id="description"
                                    rows={3}
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    aria-describedby="description-description"
                                />
                            </Field>
                            <SwitchRow
                                id="is_hidden"
                                className="sm:col-span-2"
                                title="Hidden"
                                description="Not listed in the store; still reachable by its direct link."
                                checked={form.data.is_hidden}
                                onCheckedChange={(checked) =>
                                    form.setData('is_hidden', checked)
                                }
                            />
                        </div>
                    </SectionCard>

                    <SectionCard
                        title="Payment gateways"
                        description="Carts mixing groups only offer the gateways every group allows."
                    >
                        <div className="grid gap-6">
                            <SwitchRow
                                id="restrict_gateways"
                                title="Restrict gateways"
                                description="Off: every active gateway can be used for this group."
                                checked={form.data.restrict_gateways}
                                onCheckedChange={(checked) =>
                                    form.setData('restrict_gateways', checked)
                                }
                            />
                            {form.data.restrict_gateways && (
                                <Field
                                    htmlFor="allowed_gateways"
                                    label="Allowed gateway keys"
                                    hint="Comma-separated, e.g. stripe, bank_transfer"
                                    error={
                                        form.errors.allowed_gateways ??
                                        errors['allowed_gateways.0']
                                    }
                                >
                                    <Input
                                        id="allowed_gateways"
                                        value={form.data.allowed_gateways}
                                        onChange={(event) =>
                                            form.setData(
                                                'allowed_gateways',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={
                                            !!form.errors.allowed_gateways
                                        }
                                        aria-describedby="allowed_gateways-description"
                                        className="font-mono"
                                    />
                                </Field>
                            )}
                        </div>
                    </SectionCard>

                    <FormActions>
                        <Button variant="outline" asChild>
                            <Link href={ProductGroupController.index()}>
                                Cancel
                            </Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {group ? 'Save changes' : 'Create group'}
                        </Button>
                    </FormActions>
                </form>
            </Page>
        </>
    );
}
