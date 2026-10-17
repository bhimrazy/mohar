import { Head, Link } from '@inertiajs/react';
import ProductController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ProductController';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { ProductDetailsForm } from '@/components/catalog/product/details-form';
import type { ProductEnums } from '@/components/catalog/product/details-form';
import { Button } from '@/components/ui/button';

type Props = {
    defaults: {
        product_group_id: number | null;
        auto_setup: string;
        is_taxable: boolean;
    };
    groups: { id: number; name: string }[];
    enums: ProductEnums;
};

export default function CreateProduct({ defaults, groups, enums }: Props) {
    return (
        <>
            <Head title="New product" />
            <Page size="narrow">
                <PageHeader
                    back={{
                        href: ProductController.index(),
                        label: 'Products',
                    }}
                    title="New product"
                    description="Start with the details; you'll set prices, options and provisioning next."
                />
                <ProductDetailsForm
                    action={ProductController.store()}
                    groups={groups}
                    enums={enums}
                    submitLabel="Create product"
                    stockEditable
                    cancel={
                        <Button variant="outline" asChild>
                            <Link href={ProductController.index()}>Cancel</Link>
                        </Button>
                    }
                    initial={{
                        product_group_id: defaults.product_group_id,
                        type: 'shared_hosting',
                        name: '',
                        slug: '',
                        tagline: null,
                        short_description: null,
                        description: null,
                        payment_type: 'recurring',
                        is_hidden: false,
                        is_retired: false,
                        is_featured: false,
                        is_taxable: defaults.is_taxable,
                        requires_domain: false,
                        stock_enabled: false,
                        stock_quantity: 0,
                        quantity_mode: 'single',
                        recurring_cycles_limit: 0,
                        fixed_term_days: 0,
                        fixed_term_email_key: null,
                        welcome_email_key: null,
                        upgrade_email_key: null,
                        auto_setup: defaults.auto_setup,
                        prorata_enabled: false,
                        prorata_day: 1,
                        prorata_charge_next_month_day: 0,
                        free_domain: 'none',
                        free_domain_cycles: [],
                        free_domain_tlds: [],
                        suspend_days_override: null,
                        terminate_days_override: null,
                    }}
                />
            </Page>
        </>
    );
}
