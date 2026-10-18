import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ConfigOptionGroupController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ConfigOptionGroupController';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import type { ProductChoice } from '@/components/catalog/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

export default function CreateOptionGroup({
    products,
}: {
    products: ProductChoice[];
}) {
    const form = useForm({
        name: '',
        description: '',
        product_ids: [] as number[],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(ConfigOptionGroupController.store());
    };

    return (
        <>
            <Head title="New option group" />
            <Page size="narrow">
                <PageHeader
                    back={{
                        href: ConfigOptionGroupController.index(),
                        label: 'Configurable options',
                    }}
                    title="New option group"
                    description={`Add the options and their prices next, then assign the group to products${products.length ? '' : ' once you have some'}.`}
                />
                <form onSubmit={submit} className="flex flex-col gap-6">
                    <SectionCard title="Option group">
                        <div className="grid gap-6">
                            <Field
                                htmlFor="name"
                                label="Name"
                                hint="Staff only, e.g. “VPS resources”."
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
                                />
                            </Field>
                            <Field
                                htmlFor="description"
                                label="Notes"
                                optional
                                error={form.errors.description}
                            >
                                <Textarea
                                    id="description"
                                    rows={2}
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
                        </div>
                    </SectionCard>
                    <FormActions>
                        <Button variant="outline" asChild>
                            <Link href={ConfigOptionGroupController.index()}>
                                Cancel
                            </Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Create option group
                        </Button>
                    </FormActions>
                </form>
            </Page>
        </>
    );
}
