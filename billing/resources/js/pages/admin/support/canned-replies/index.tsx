import { Form, Head, Link } from '@inertiajs/react';
import { MessageSquareText, Plus, X } from 'lucide-react';
import CannedReplyCategoryController from '@/actions/App/Modules/Support/Http/Controllers/Admin/CannedReplyCategoryController';
import CannedReplyController from '@/actions/App/Modules/Support/Http/Controllers/Admin/CannedReplyController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { SelectOption } from '@/types/app';

type Reply = {
    id: number;
    name: string;
    category: string | null;
    excerpt: string;
    updated_at: string | null;
};

const columns: DataTableColumn<Reply>[] = [
    {
        key: 'name',
        header: 'Name',
        primary: true,
        cell: (reply) => reply.name,
    },
    {
        key: 'category',
        header: 'Category',
        cell: (reply) =>
            reply.category ?? <span className="text-muted-foreground">—</span>,
    },
    {
        key: 'excerpt',
        header: 'Preview',
        hideOnMobile: true,
        className: 'max-w-md',
        cell: (reply) => (
            <span className="block truncate text-muted-foreground">
                {reply.excerpt}
            </span>
        ),
    },
    {
        key: 'updated',
        header: 'Updated',
        align: 'right',
        cell: (reply) => <DateTime value={reply.updated_at} relative />,
    },
];

export default function CannedRepliesIndex({
    replies,
    categories,
    filters,
}: {
    replies: Reply[];
    categories: SelectOption[];
    filters: Record<string, string>;
}) {
    const filtered = Boolean(filters.search || filters.category);

    return (
        <>
            <Head title="Canned replies" />
            <Page>
                <PageHeader
                    title="Canned replies"
                    description="Reusable answers staff can insert into replies. Tokens are filled in when sent."
                    actions={
                        <Button asChild>
                            <Link href={CannedReplyController.create()}>
                                <Plus />
                                New canned reply
                            </Link>
                        </Button>
                    }
                />
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="flex flex-col gap-4 lg:col-span-2">
                        <FilterBar
                            searchPlaceholder="Search name or text…"
                            filterKeys={['category']}
                            filters={
                                <FilterSelect
                                    name="category"
                                    label="Category"
                                    allLabel="All categories"
                                    options={categories}
                                />
                            }
                        />
                        <DataTable
                            caption="Canned replies"
                            columns={columns}
                            rows={replies}
                            getRowKey={(reply) => reply.id}
                            rowHref={(reply) =>
                                CannedReplyController.edit(reply.id)
                            }
                            empty={
                                <EmptyState
                                    icon={MessageSquareText}
                                    title={
                                        filtered
                                            ? 'No canned replies match these filters'
                                            : 'No canned replies yet'
                                    }
                                    description={
                                        filtered
                                            ? 'Try another search.'
                                            : 'Save answers you send often to reply faster.'
                                    }
                                />
                            }
                        />
                    </div>
                    <SectionCard title="Categories">
                        <ul className="mb-4 grid gap-1">
                            {categories.map((category) => (
                                <li
                                    key={category.value}
                                    className="flex items-center justify-between gap-2 rounded-md px-2 py-1 text-sm hover:bg-muted/50"
                                >
                                    <span className="truncate">
                                        {category.label}
                                    </span>
                                    <ConfirmDialog
                                        trigger={
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-7"
                                                aria-label={`Delete category ${category.label}`}
                                            >
                                                <X className="size-3.5" />
                                            </Button>
                                        }
                                        title={`Delete category ${category.label}?`}
                                        description="Its canned replies are kept without a category."
                                        confirmLabel="Delete category"
                                        action={CannedReplyCategoryController.destroy(
                                            Number(category.value),
                                        )}
                                    />
                                </li>
                            ))}
                            {categories.length === 0 && (
                                <li className="text-sm text-muted-foreground">
                                    No categories yet.
                                </li>
                            )}
                        </ul>
                        <Form
                            {...CannedReplyCategoryController.store.form()}
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="flex gap-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <Input
                                        name="name"
                                        placeholder="New category"
                                        aria-label="New category name"
                                        aria-invalid={!!errors.name}
                                        required
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Add
                                    </Button>
                                </>
                            )}
                        </Form>
                    </SectionCard>
                </div>
            </Page>
        </>
    );
}
