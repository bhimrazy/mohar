import { Head, Link } from '@inertiajs/react';
import { Mail, Plus } from 'lucide-react';
import EmailTemplateController from '@/actions/App/Modules/Notifications/Http/Controllers/Admin/EmailTemplateController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { StatusBadge } from '@/components/app/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { SelectOption } from '@/types/app';

type Template = {
    id: number;
    key: string;
    name: string;
    type: string;
    audience: string;
    is_system: boolean;
    is_enabled: boolean;
    subject: string | null;
    sample_subject: string | null;
    description: string | null;
    locales: string[];
    updated_at: string | null;
};

export default function TemplatesIndex({
    templates,
    types,
    filters,
    canManage,
}: {
    templates: Template[];
    types: SelectOption[];
    filters: Record<string, string>;
    canManage: boolean;
}) {
    const typeLabel = (value: string) =>
        types.find((type) => type.value === value)?.label ?? value;

    const columns: DataTableColumn<Template>[] = [
        {
            key: 'name',
            header: 'Template',
            primary: true,
            cell: (template) => (
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="truncate font-medium">
                            {template.name}
                        </span>
                        {!template.is_system && (
                            <Badge variant="outline">Custom</Badge>
                        )}
                    </div>
                    {template.description && (
                        <div
                            className="line-clamp-2 text-xs text-muted-foreground"
                            title={`Key: ${template.key}`}
                        >
                            {template.description}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'subject',
            header: 'Subject (sample)',
            hideOnMobile: true,
            cell: (template) => (
                <span
                    className="block max-w-[26rem] truncate text-sm text-muted-foreground"
                    title={template.subject ?? undefined}
                >
                    {template.sample_subject ?? template.subject}
                </span>
            ),
        },
        {
            key: 'type',
            header: 'Type',
            cell: (template) => (
                <span className="text-sm">{typeLabel(template.type)}</span>
            ),
        },
        {
            key: 'locales',
            header: 'Languages',
            hideOnMobile: true,
            cell: (template) => (
                <span className="font-mono text-xs uppercase">
                    {template.locales.join(' · ')}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (template) => (
                <StatusBadge
                    status={template.is_enabled ? 'active' : 'inactive'}
                    label={template.is_enabled ? 'Enabled' : 'Disabled'}
                />
            ),
        },
        {
            key: 'updated',
            header: 'Edited',
            align: 'right',
            hideOnMobile: true,
            cell: (template) => (
                <DateTime value={template.updated_at} relative />
            ),
        },
    ];

    return (
        <>
            <Head title="Email templates" />
            <Page>
                <PageHeader
                    title="Email templates"
                    description="What clients and staff receive for each event. Text is Markdown with merge fields like {{ client.first_name }}."
                    actions={
                        canManage && (
                            <Button asChild>
                                <Link href={EmailTemplateController.create()}>
                                    <Plus />
                                    New template
                                </Link>
                            </Button>
                        )
                    }
                />
                <div className="flex flex-col gap-4">
                    <FilterBar
                        searchPlaceholder="Search name or key…"
                        filterKeys={['type']}
                        filters={
                            <FilterSelect
                                name="type"
                                label="Type"
                                allLabel="All types"
                                options={types}
                            />
                        }
                    />
                    <DataTable
                        caption="Email templates"
                        columns={columns}
                        rows={templates}
                        getRowKey={(template) => template.id}
                        rowHref={(template) =>
                            EmailTemplateController.edit(template.id)
                        }
                        empty={
                            <EmptyState
                                icon={Mail}
                                title={
                                    filters.search || filters.type
                                        ? 'No templates match these filters'
                                        : 'No email templates'
                                }
                                description={
                                    filters.search || filters.type
                                        ? 'Clear the search or pick another type.'
                                        : 'Run the database seeder to install the default templates.'
                                }
                            />
                        }
                    />
                </div>
            </Page>
        </>
    );
}
