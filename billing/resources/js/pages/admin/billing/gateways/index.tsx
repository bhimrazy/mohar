import { Head } from '@inertiajs/react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { StatusBadge } from '@/components/app/status-badge';
import { GatewayLogo } from '@/components/billing/gateway-logo';
import type { GatewayLogoData } from '@/components/billing/gateway-logo';
import { humanize } from '@/components/billing/types';
import { edit } from '@/actions/App/Modules/Billing/Http/Controllers/Admin/GatewayController';

type GatewayRow = {
    key: string;
    driver_name: string;
    display_name: string;
    is_active: boolean;
    configured: boolean;
    show_on_order_form: boolean;
    sort_order: number;
    convert_to_currency: string | null;
    capabilities: string[];
    sandbox: boolean;
    logo: GatewayLogoData | null;
};

export default function GatewaysIndex({
    gateways,
}: {
    gateways: GatewayRow[];
}) {
    const columns: DataTableColumn<GatewayRow>[] = [
        {
            key: 'name',
            header: 'Gateway',
            primary: true,
            cell: (gateway) => (
                <div>
                    <div className="flex items-center gap-2">
                        {gateway.logo && (
                            <GatewayLogo
                                name={gateway.display_name}
                                logo={gateway.logo}
                                decorative
                                className="my-0 h-8"
                            />
                        )}
                        {gateway.display_name}
                        {gateway.sandbox && (
                            <StatusBadge
                                status="sandbox"
                                tone="warning"
                                label="Sandbox"
                            />
                        )}
                    </div>
                    <div className="text-xs text-muted-foreground">
                        {gateway.driver_name}
                    </div>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (gateway) => (
                <StatusBadge
                    status={gateway.is_active ? 'active' : 'inactive'}
                />
            ),
        },
        {
            key: 'clients',
            header: 'Shown to clients',
            hideOnMobile: true,
            cell: (gateway) => (gateway.show_on_order_form ? 'Yes' : 'No'),
        },
        {
            key: 'capabilities',
            header: 'Capabilities',
            hideOnMobile: true,
            cell: (gateway) =>
                gateway.capabilities.length ? (
                    <span className="text-sm text-muted-foreground">
                        {gateway.capabilities.map(humanize).join(', ')}
                    </span>
                ) : (
                    <span className="text-sm text-muted-foreground">
                        Offline, staff-recorded
                    </span>
                ),
        },
        {
            key: 'currency',
            header: 'Converts to',
            hideOnMobile: true,
            cell: (gateway) => gateway.convert_to_currency ?? '—',
        },
    ];

    return (
        <>
            <Head title="Payment gateways" />
            <Page>
                <PageHeader
                    title="Payment gateways"
                    description="Enable the ways clients can pay and configure each gateway."
                />
                <DataTable
                    caption="Payment gateways"
                    columns={columns}
                    rows={gateways}
                    getRowKey={(gateway) => gateway.key}
                    rowHref={(gateway) => edit(gateway.key)}
                />
            </Page>
        </>
    );
}
