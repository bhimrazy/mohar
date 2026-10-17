import { Form, Head, Link } from '@inertiajs/react';
import { BookOpen, EyeOff, Lock, Plus, ThumbsUp, X } from 'lucide-react';
import KbArticleController from '@/actions/App/Modules/Support/Http/Controllers/Admin/KbArticleController';
import KbCategoryController from '@/actions/App/Modules/Support/Http/Controllers/Admin/KbCategoryController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { LaravelPaginated } from '@/types/app';

type Article = {
    id: number;
    title: string;
    slug: string;
    is_published: boolean;
    is_private: boolean;
    views: number;
    votes_up: number;
    votes_down: number;
    categories: string[];
    updated_at: string | null;
};

type Category = { id: number; name: string; slug: string; parent_id: number | null; is_hidden: boolean; articles_count: number };

const columns: DataTableColumn<Article>[] = [
    {
        key: 'title',
        header: 'Article',
        primary: true,
        cell: (article) => (
            <span className="flex items-center gap-2">
                {article.is_private && <Lock className="size-3.5 shrink-0 text-muted-foreground" aria-label="Login required" />}
                <span className="truncate">{article.title}</span>
            </span>
        ),
    },
    { key: 'categories', header: 'Categories', cell: (article) => article.categories.join(', ') || <span className="text-muted-foreground">—</span> },
    { key: 'status', header: 'Status', cell: (article) => <StatusBadge status={article.is_published ? 'published' : 'draft'} /> },
    { key: 'views', header: 'Views', align: 'right', cell: (article) => <span className="tabular-nums">{article.views.toLocaleString()}</span> },
    {
        key: 'helpful',
        header: 'Helpful',
        align: 'right',
        hideOnMobile: true,
        cell: (article) => (
            <span className="inline-flex items-center gap-1 tabular-nums text-muted-foreground">
                <ThumbsUp className="size-3.5" aria-hidden />
                {article.votes_up} / {article.votes_up + article.votes_down}
            </span>
        ),
    },
    { key: 'updated', header: 'Updated', align: 'right', hideOnMobile: true, cell: (article) => <DateTime value={article.updated_at} relative /> },
];

export default function KnowledgeBaseIndex({ articles, categories, filters }: { articles: LaravelPaginated<Article>; categories: Category[]; filters: Record<string, string> }) {
    const options = categories.map((category) => ({ value: String(category.id), label: category.name }));
    const topLevel = categories.filter((category) => category.parent_id === null);
    const filtered = Boolean(filters.search || filters.category || filters.state);

    return (
        <>
            <Head title="Knowledge base" />
            <Page>
                <PageHeader
                    title="Knowledge base"
                    description="Help articles shown on the public knowledge base."
                    actions={
                        <Button asChild>
                            <Link href={KbArticleController.create()}>
                                <Plus />
                                New article
                            </Link>
                        </Button>
                    }
                />
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                        <FilterBar
                            searchPlaceholder="Search articles…"
                            filterKeys={['category', 'state']}
                            filters={
                                <>
                                    <FilterSelect name="category" label="Category" allLabel="All categories" options={options} />
                                    <FilterSelect name="state" label="Status" allLabel="All statuses" options={[{ value: 'published', label: 'Published' }, { value: 'draft', label: 'Draft' }]} />
                                </>
                            }
                        />
                        <DataTable
                            caption="Articles"
                            columns={columns}
                            rows={articles.data}
                            getRowKey={(article) => article.id}
                            rowHref={(article) => KbArticleController.edit(article.id)}
                            empty={<EmptyState icon={BookOpen} title={filtered ? 'No articles match these filters' : 'No articles yet'} description={filtered ? 'Try another search.' : 'Write answers to common questions so clients can help themselves.'} />}
                            footer={<Pagination paginator={articles} itemLabel="articles" />}
                        />
                    </div>
                    <SectionCard title="Categories" description="One level of sub-categories.">
                        <ul className="mb-4 grid gap-1">
                            {topLevel.map((category) => (
                                <CategoryRow key={category.id} category={category}>
                                    {categories.filter((child) => child.parent_id === category.id).map((child) => (
                                        <CategoryRow key={child.id} category={child} nested />
                                    ))}
                                </CategoryRow>
                            ))}
                            {categories.length === 0 && <li className="text-sm text-muted-foreground">No categories yet.</li>}
                        </ul>
                        <Form {...KbCategoryController.store.form()} resetOnSuccess options={{ preserveScroll: true }} className="grid gap-2">
                            {({ processing, errors }) => (
                                <>
                                    <Input name="name" placeholder="New category name" aria-label="New category name" required aria-invalid={!!errors.name} />
                                    <div className="flex gap-2">
                                        <Select name="parent_id">
                                            <SelectTrigger className="w-full" aria-label="Parent category">
                                                <SelectValue placeholder="Top level" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {topLevel.map((category) => (
                                                    <SelectItem key={category.id} value={String(category.id)}>
                                                        Inside {category.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <Button type="submit" variant="outline" disabled={processing}>
                                            Add category
                                        </Button>
                                    </div>
                                    {(errors.name || errors.parent_id) && <p className="text-sm text-destructive">{errors.name ?? errors.parent_id}</p>}
                                </>
                            )}
                        </Form>
                    </SectionCard>
                </div>
            </Page>
        </>
    );
}

function CategoryRow({ category, nested = false, children }: { category: Category; nested?: boolean; children?: React.ReactNode }) {
    return (
        <li>
            <div className={`flex items-center justify-between gap-2 rounded-md px-2 py-1 text-sm hover:bg-muted/50 ${nested ? 'ml-4' : ''}`}>
                <span className="flex min-w-0 items-center gap-2">
                    <span className="truncate">{category.name}</span>
                    {category.is_hidden && <EyeOff className="size-3.5 text-muted-foreground" aria-label="Hidden" />}
                    <span className="text-xs text-muted-foreground tabular-nums">{category.articles_count}</span>
                </span>
                <ConfirmDialog
                    trigger={
                        <Button variant="ghost" size="icon" className="size-7" aria-label={`Delete category ${category.name}`}>
                            <X className="size-3.5" />
                        </Button>
                    }
                    title={`Delete category ${category.name}?`}
                    description="Articles are kept; they are only removed from this category."
                    confirmLabel="Delete category"
                    action={KbCategoryController.destroy(category.id)}
                />
            </div>
            {children && <ul className="grid gap-1">{children}</ul>}
        </li>
    );
}
