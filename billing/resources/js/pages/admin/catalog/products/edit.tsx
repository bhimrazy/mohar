import { Head, router } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import ProductController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ProductController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { useQueryParams } from '@/components/app/use-query-params';
import { CustomFieldsTab } from '@/components/catalog/product/custom-fields-tab';
import type { CustomField } from '@/components/catalog/product/custom-fields-tab';
import { ProductDetailsForm } from '@/components/catalog/product/details-form';
import type {
    ProductDetails,
    ProductEnums,
} from '@/components/catalog/product/details-form';
import { PricingTab } from '@/components/catalog/product/pricing-tab';
import { ProvisioningTab } from '@/components/catalog/product/provisioning-tab';
import { StockCard } from '@/components/catalog/product/stock-card';
import {
    AddonsTab,
    ConfigOptionsTab,
    UpgradesTab,
} from '@/components/catalog/product/relation-tabs';
import type {
    CurrencyOption,
    CycleOption,
    PriceRow,
    ProductChoice,
} from '@/components/catalog/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

type Product = ProductDetails & {
    id: number;
    group: { id: number; name: string };
    status: string;
    provisioning_driver: string;
    server_group_id: number | null;
    driver_config: Record<string, unknown> | unknown[];
    allow_config_option_upgrades: boolean;
};

type Props = {
    product: Product;
    tab: string;
    activeServices: number;
    canManage: boolean;
    groups: { id: number; name: string }[];
    enums: ProductEnums;
    currencies: CurrencyOption[];
    pricing: { cycles: CycleOption[]; rows: PriceRow[] };
    configOptionGroups: {
        id: number;
        name: string;
        description: string | null;
        options_count: number;
    }[];
    assignedGroupIds: number[];
    customFields: CustomField[];
    addons: {
        id: number;
        name: string;
        status: string;
        payment_type: string;
        show_on_order: boolean;
    }[];
    assignedAddonIds: number[];
    upgradeCandidates: ProductChoice[];
    upgradeTargetIds: number[];
    driverSuggestions: string[];
};

const TABS = [
    { value: 'details', label: 'Details' },
    { value: 'pricing', label: 'Pricing' },
    { value: 'options', label: 'Configurable options' },
    { value: 'fields', label: 'Custom fields' },
    { value: 'addons', label: 'Addons' },
    { value: 'upgrades', label: 'Upgrades' },
    { value: 'provisioning', label: 'Provisioning' },
];

export default function EditProduct(props: Props) {
    const { product, canManage } = props;
    const { hrefWith } = useQueryParams();
    const [tab, setTab] = useState(props.tab);
    const [confirmDelete, setConfirmDelete] = useState(false);

    const changeTab = (next: string) => {
        setTab(next);
        window.history.replaceState(
            window.history.state,
            '',
            hrefWith({ tab: next === 'details' ? null : next }),
        );
    };

    return (
        <>
            <Head title={product.name} />
            <Page>
                <PageHeader
                    back={{
                        href: ProductController.index(),
                        label: 'Products',
                    }}
                    title={product.name}
                    status={product.status}
                    badges={
                        !product.is_taxable && (
                            <Badge variant="outline">Untaxed</Badge>
                        )
                    }
                    meta={
                        <>
                            <span>{product.group.name}</span>
                            <span aria-hidden="true">·</span>
                            <span>
                                {
                                    props.enums.types.find(
                                        (type) => type.value === product.type,
                                    )?.label
                                }
                            </span>
                            <span aria-hidden="true">·</span>
                            <span>
                                {props.activeServices} active{' '}
                                {props.activeServices === 1
                                    ? 'service'
                                    : 'services'}
                            </span>
                        </>
                    }
                    actions={
                        canManage && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        aria-label="More actions"
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            router.post(
                                                ProductController.duplicate.url(
                                                    product,
                                                ),
                                            )
                                        }
                                    >
                                        Duplicate product
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={() => setConfirmDelete(true)}
                                    >
                                        Delete product
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )
                    }
                />

                <Tabs value={tab} onValueChange={changeTab} className="gap-6">
                    <div className="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
                        <TabsList>
                            {TABS.map((item) => (
                                <TabsTrigger
                                    key={item.value}
                                    value={item.value}
                                >
                                    {item.label}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </div>

                    <TabsContent value="details" className="max-w-3xl">
                        <div className="flex flex-col gap-6">
                            {product.stock_enabled && (
                                <StockCard
                                    productId={product.id}
                                    quantity={product.stock_quantity}
                                    canManage={canManage}
                                />
                            )}
                            <ProductDetailsForm
                                initial={product}
                                action={ProductController.update(product)}
                                groups={props.groups}
                                enums={props.enums}
                                submitLabel="Save changes"
                                disabled={!canManage}
                            />
                        </div>
                    </TabsContent>

                    <TabsContent value="pricing">
                        <PricingTab
                            productId={product.id}
                            paymentType={product.payment_type}
                            currencies={props.currencies}
                            cycles={props.pricing.cycles}
                            rows={props.pricing.rows}
                            canManage={canManage}
                        />
                    </TabsContent>

                    <TabsContent value="options" className="max-w-3xl">
                        <ConfigOptionsTab
                            productId={product.id}
                            groups={props.configOptionGroups}
                            assigned={props.assignedGroupIds}
                            canManage={canManage}
                        />
                    </TabsContent>

                    <TabsContent value="fields">
                        <CustomFieldsTab
                            productId={product.id}
                            fields={props.customFields}
                            types={props.enums.customFieldTypes}
                            canManage={canManage}
                        />
                    </TabsContent>

                    <TabsContent value="addons" className="max-w-3xl">
                        <AddonsTab
                            productId={product.id}
                            addons={props.addons}
                            assigned={props.assignedAddonIds}
                            canManage={canManage}
                        />
                    </TabsContent>

                    <TabsContent value="upgrades" className="max-w-3xl">
                        <UpgradesTab
                            productId={product.id}
                            candidates={props.upgradeCandidates}
                            targets={props.upgradeTargetIds}
                            allowConfigOptionUpgrades={
                                product.allow_config_option_upgrades
                            }
                            canManage={canManage}
                        />
                    </TabsContent>

                    <TabsContent value="provisioning" className="max-w-3xl">
                        <ProvisioningTab
                            productId={product.id}
                            driver={product.provisioning_driver}
                            serverGroupId={product.server_group_id}
                            driverConfig={product.driver_config}
                            suggestions={props.driverSuggestions}
                            canManage={canManage}
                        />
                    </TabsContent>
                </Tabs>
            </Page>

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title={`Delete ${product.name}?`}
                description="The product is removed from the catalog. Products with services can't be deleted; retire them instead so existing services keep working."
                confirmLabel="Delete product"
                action={ProductController.destroy(product)}
            />
        </>
    );
}
