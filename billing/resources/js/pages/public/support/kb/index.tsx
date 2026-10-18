import { Link, router } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { BookOpen, Search } from 'lucide-react';
import { useState } from 'react';
import KnowledgebaseController from '@/actions/App/Modules/Support/Http/Controllers/Public/KnowledgebaseController';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { ArticleList } from '@/components/support/kb-article-list';
import type {
    KbArticleItem,
    KbCategoryCard,
} from '@/components/support/kb-article-list';
import { PublicSupportNav } from '@/components/support/public-nav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    categories: (KbCategoryCard & {
        articles_count: number;
        children: KbCategoryCard[];
    })[];
    popular: KbArticleItem[];
    results: KbArticleItem[] | null;
    query: string;
};

export default function KnowledgeBase({
    categories,
    popular,
    results,
    query,
}: Props) {
    const [term, setTerm] = useState(query);

    return (
        <>
            <Head title="Knowledge base" />
            <Page>
                <PublicSupportNav />
                <PageHeader
                    title="Knowledge base"
                    description="Answers to common questions about hosting, email, domains and billing."
                />
                <form
                    role="search"
                    className="flex max-w-xl gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            KnowledgebaseController.index.url({
                                query: term.trim() ? { q: term.trim() } : {},
                            }),
                            {},
                            { preserveState: true },
                        );
                    }}
                >
                    <div className="relative flex-1">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden
                        />
                        <Input
                            type="search"
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                            placeholder="Search articles, e.g. DNS"
                            aria-label="Search the knowledge base"
                            className="pl-8"
                        />
                    </div>
                    <Button type="submit">Search</Button>
                </form>

                {results !== null ? (
                    <SectionCard
                        title={`${results.length} ${results.length === 1 ? 'result' : 'results'} for "${query}"`}
                        flush
                    >
                        {results.length > 0 ? (
                            <ArticleList articles={results} />
                        ) : (
                            <EmptyState
                                icon={Search}
                                title="No articles found"
                                description="Try fewer or different words, or contact support."
                            />
                        )}
                    </SectionCard>
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {categories.map((category) => (
                                <Link
                                    key={category.slug}
                                    href={KnowledgebaseController.category(
                                        category.slug,
                                    )}
                                    className="flex flex-col gap-2 rounded-xl border bg-card p-5 shadow-xs transition-colors hover:bg-muted/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                >
                                    <span className="flex items-center justify-between gap-2">
                                        <span className="font-medium">
                                            {category.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            {category.articles_count}{' '}
                                            {category.articles_count === 1
                                                ? 'article'
                                                : 'articles'}
                                        </span>
                                    </span>
                                    {category.description && (
                                        <span className="text-sm text-muted-foreground">
                                            {category.description}
                                        </span>
                                    )}
                                    {category.children.length > 0 && (
                                        <span className="text-xs text-muted-foreground">
                                            Includes{' '}
                                            {category.children
                                                .map((child) => child.name)
                                                .join(', ')}
                                        </span>
                                    )}
                                </Link>
                            ))}
                        </div>
                        {categories.length === 0 && (
                            <EmptyState
                                icon={BookOpen}
                                variant="bordered"
                                title="No help articles yet"
                                description="Check back soon, or contact support with your question."
                            />
                        )}
                        {popular.length > 0 && (
                            <SectionCard title="Popular articles" flush>
                                <ArticleList articles={popular} />
                            </SectionCard>
                        )}
                    </>
                )}
            </Page>
        </>
    );
}
